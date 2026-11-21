#!/usr/bin/env python3
"""
Ner Michoel shiur audio cleanup helper.

The website (Bluehost shared hosting) cannot run a noise-removal model, so this
small program does the work on any computer (the office PC, a laptop, a cloud
machine). It asks the website for the next shiur to clean, downloads it, cleans
it and sends the cleaned file back. The website never sends it anything else.

What it does to each recording:
  1. Picks the better track. A Sony recorder gives two tracks (left/right, or two
     files). It scores each one (how far the voice stands above the background
     hiss, how much of it is clipped/distorted, how quiet it is) and keeps the
     best one instead of mixing them.
  2. Removes low rumble (air conditioning, chairs, table knocks) under 70 Hz.
  3. Removes background noise and room echo with DeepFilterNet 3 (free, open
     source). If DeepFilterNet is not installed it falls back to ffmpeg's own,
     simpler noise filter, and says so in the report.
  4. Optionally shortens long silences.
  5. Evens out the volume (EBU R128 loudness, like radio).
  6. Compresses to a small mono MP3 (48 kbps by default, same as the site's
     current files: about 21 MB per hour).

Setup (once):
  - Python 3.9 or newer
  - ffmpeg on the PATH (Windows: `winget install Gyan.FFmpeg`)
  - pip install "numpy<2"
  - for the good noise removal (needs Python 3.8-3.11; DeepFilterNet has no
    newer builds): pip install torch torchaudio deepfilternet==0.5.6
    (CPU is fine: about 1 minute of work per 7-15 minutes of audio)
  On Kaggle the website sets all of this up by itself.

Run:
  python audio-cleanup-helper.py --site https://example.org/ --key THE_KEY
      keeps running and checks the site for work every minute
  python audio-cleanup-helper.py --site ... --key ... --once
      does what is waiting, then stops (good for Windows Task Scheduler)
  python audio-cleanup-helper.py --file in.mp3 [in2.mp3] --out out.mp3
      cleans local files only, no website (for trying it out)

The key comes from the website: WordPress admin > Experiments > Shiur audio
cleanup > The cleanup helper. It can also be put in the NM_CLEANUP_KEY
environment variable instead of --key.
"""

import argparse
import json
import math
import os
import shutil
import subprocess
import sys
import tempfile
import time
import types
import urllib.error
import urllib.parse
import urllib.request
import uuid
import wave

__version__ = "1.0.0"

STRENGTH_DB = {"gentle": 12.0, "normal": 24.0, "strong": None}  # None = no limit
AFFTDN_NR = {"gentle": 8, "normal": 14, "strong": 22}  # fallback filter, dB


def log(msg):
    print(time.strftime("%H:%M:%S"), msg, flush=True)


# ---------------------------------------------------------------- ffmpeg

def need_ffmpeg():
    for tool in ("ffmpeg", "ffprobe"):
        if not shutil.which(tool):
            sys.exit(f"{tool} was not found. Install ffmpeg and make sure it is on the PATH.")


def run(cmd):
    p = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if p.returncode != 0:
        raise RuntimeError("ffmpeg failed: " + p.stderr.decode("utf-8", "replace")[-800:])
    return p


def probe(path):
    p = run(["ffprobe", "-v", "error", "-select_streams", "a:0", "-show_entries",
             "stream=channels,sample_rate:format=duration", "-of", "json", path])
    d = json.loads(p.stdout.decode("utf-8"))
    st = (d.get("streams") or [{}])[0]
    return {
        "channels": int(st.get("channels") or 1),
        "sample_rate": int(st.get("sample_rate") or 0),
        "duration": float((d.get("format") or {}).get("duration") or 0),
    }


def decode_channel(src, channel, out_wav, rate, limit_s=None):
    """One channel of src as a mono 16-bit wav at `rate`."""
    cmd = ["ffmpeg", "-v", "error", "-y", "-i", src]
    if limit_s:
        cmd += ["-t", str(limit_s)]
    cmd += ["-map", "0:a:0", "-af", f"pan=mono|c0=c{channel}", "-ar", str(rate),
            "-c:a", "pcm_s16le", out_wav]
    run(cmd)


def read_wav(path):
    import numpy as np
    with wave.open(path, "rb") as w:
        rate = w.getframerate()
        data = np.frombuffer(w.readframes(w.getnframes()), dtype="<i2")
    return data.astype("float32") / 32768.0, rate


def write_wav(path, samples, rate):
    import numpy as np
    s = np.clip(samples, -1.0, 1.0)
    with wave.open(path, "wb") as w:
        w.setnchannels(1)
        w.setsampwidth(2)
        w.setframerate(rate)
        w.writeframes((s * 32767.0).astype("<i2").tobytes())


# ---------------------------------------------------------------- scoring

def level_stats(samples, rate):
    """Frame levels in dB: background (10th pct), voice (90th pct), clipping."""
    import numpy as np
    frame = max(1, int(rate * 0.05))
    n = len(samples) // frame
    if n < 4:
        return {"noise_db": -90.0, "voice_db": -90.0, "snr_db": 0.0, "clipped_pct": 0.0}
    fr = samples[: n * frame].reshape(n, frame)
    rms = np.sqrt(np.mean(fr * fr, axis=1) + 1e-12)
    db = 20 * np.log10(rms + 1e-9)
    noise = float(np.percentile(db, 10))
    voice = float(np.percentile(db, 90))
    clipped = float(np.mean(np.abs(samples) > 0.985) * 100.0)
    return {"noise_db": round(noise, 1), "voice_db": round(voice, 1),
            "snr_db": round(voice - noise, 1), "clipped_pct": round(clipped, 3)}


def track_score(st):
    """Higher is better. Voice above background counts most; clipping and a
    track that is barely there (far mic, channel switched off) count against."""
    score = st["snr_db"]
    score -= min(30.0, st["clipped_pct"] * 15.0)
    if st["voice_db"] < -50:
        score -= 20
    return round(score, 1)


def pick_best_track(sources, work, limit_s):
    """Every channel of every source file is a candidate. Score each on a
    16 kHz copy of (up to) its first 10 minutes and return the best."""
    import numpy as np
    candidates = []
    for si, src in enumerate(sources):
        info = probe(src)
        for ch in range(min(info["channels"], 4)):
            tmp = os.path.join(work, f"score_{si}_{ch}.wav")
            decode_channel(src, ch, tmp, 16000, limit_s=min(limit_s or 600, 600))
            samples, rate = read_wav(tmp)
            st = level_stats(samples, rate)
            name = (["first file", "second file", "third file"][si] if si < 3 else f"file {si + 1}") if len(sources) > 1 else "file"
            if info["channels"] == 2:
                label = f"{'left' if ch == 0 else 'right'} track of the {name}"
            elif info["channels"] > 2:
                label = f"track {ch + 1} of the {name}"
            else:
                label = name
            candidates.append({"source": si, "channel": ch, "label": label, "stats": st,
                               "score": track_score(st), "_samples": samples})
            os.remove(tmp)
    # Two copies of the same microphone (a "stereo" file that is really mono):
    # nothing to choose, say so.
    same = False
    if len(candidates) == 2:
        a, b = candidates[0]["_samples"], candidates[1]["_samples"]
        n = min(len(a), len(b))
        if n > 16000 and np.std(a[:n]) > 0 and np.std(b[:n]) > 0:
            same = float(np.corrcoef(a[:n], b[:n])[0, 1]) > 0.995
    for c in candidates:
        del c["_samples"]
    best = max(candidates, key=lambda c: c["score"])
    return best, candidates, same


# ---------------------------------------------------------------- denoise

_DF = None


def load_deepfilter(model_dir=None):
    """DeepFilterNet 3, or None when it is not installed."""
    global _DF
    if _DF is not None:
        return _DF or None
    try:
        try:  # newer torchaudio dropped a module DeepFilterNet 0.5.6 imports
            import torchaudio.backend.common  # noqa: F401
        except Exception:
            back = types.ModuleType("torchaudio.backend")
            common = types.ModuleType("torchaudio.backend.common")

            class AudioMetaData:  # only used as a type name
                pass

            common.AudioMetaData = AudioMetaData
            back.common = common
            sys.modules["torchaudio.backend"] = back
            sys.modules["torchaudio.backend.common"] = common
        import torch  # noqa: F401
        from df.enhance import enhance, init_df
        model, state, _ = init_df(model_base_dir=model_dir, log_level="ERROR", log_file=None)
        _DF = (model, state, enhance)
    except Exception as e:  # noqa: BLE001
        log(f"DeepFilterNet not available ({e.__class__.__name__}: {e}); using the simpler ffmpeg filter.")
        _DF = False
    return _DF or None


def deepfilter_wav(in_wav, out_wav, strength, model_dir=None):
    """Denoise a 48 kHz mono wav in 60 s pieces (keeps memory low on long
    shiurim) with a short cross-fade so the joins can't be heard."""
    import numpy as np
    import torch
    model, state, enhance = load_deepfilter(model_dir)
    samples, rate = read_wav(in_wav)
    sr = state.sr()
    if rate != sr:
        raise RuntimeError(f"expected {sr} Hz audio, got {rate}")
    chunk, fade = 60 * sr, sr // 2
    out = np.zeros_like(samples)
    pos = 0
    lim = STRENGTH_DB.get(strength, 24.0)
    while pos < len(samples):
        start = max(0, pos - fade)
        end = min(len(samples), pos + chunk)
        piece = torch.from_numpy(samples[start:end].copy()).unsqueeze(0)
        with torch.no_grad():
            enh = enhance(model, state, piece, atten_lim_db=lim).squeeze(0).numpy()
        if start < pos:  # cross-fade over the overlap
            k = pos - start
            ramp = np.linspace(0.0, 1.0, k, dtype="float32")
            out[start:pos] = out[start:pos] * (1 - ramp) + enh[:k] * ramp
            out[pos:end] = enh[k:]
        else:
            out[start:end] = enh
        pos = end
    write_wav(out_wav, out, sr)


# ---------------------------------------------------------------- pipeline

def clean(sources, out_mp3, opts, model_dir=None):
    """Clean `sources` (1 or 2 local files) into out_mp3. Returns the report."""
    import numpy as np  # noqa: F401  (fail early with a clear message)
    t0 = time.time()
    strength = opts.get("strength", "normal")
    limit_s = int(opts.get("limit_seconds") or 0) or None
    bitrate = int(opts.get("bitrate_kbps") or 48)
    work = tempfile.mkdtemp(prefix="nm-clean-")
    try:
        best, candidates, same = pick_best_track(sources, work, limit_s)
        src = sources[best["source"]]
        log(f"using: {best['label']} (score {best['score']})")

        raw48 = os.path.join(work, "raw48.wav")
        decode_channel(src, best["channel"], raw48, 48000, limit_s)
        before_samples, _ = read_wav(raw48)
        before = level_stats(before_samples, 48000)
        del before_samples

        hp = os.path.join(work, "hp.wav")
        run(["ffmpeg", "-v", "error", "-y", "-i", raw48, "-af", "highpass=f=70",
             "-c:a", "pcm_s16le", hp])

        den = os.path.join(work, "den.wav")
        engine = "DeepFilterNet 3"
        if load_deepfilter(model_dir):
            deepfilter_wav(hp, den, strength, model_dir)
        else:
            engine = "ffmpeg afftdn (simpler; install DeepFilterNet for better results)"
            run(["ffmpeg", "-v", "error", "-y", "-i", hp, "-af",
                 f"afftdn=nr={AFFTDN_NR.get(strength, 14)}:nf=-40:tn=1",
                 "-c:a", "pcm_s16le", den])

        chain = []
        if opts.get("shorten_silence"):
            # pauses longer than 2 s become 1 s
            chain.append("silenceremove=stop_periods=-1:stop_duration=2:stop_threshold=-45dB:stop_silence=1")
        if opts.get("normalize", True):
            chain.append("loudnorm=I=-16:LRA=11:TP=-1.5")
        chain.append("aresample=32000" if bitrate <= 56 else "aresample=44100")
        run(["ffmpeg", "-v", "error", "-y", "-i", den, "-af", ",".join(chain), "-ac", "1",
             "-c:a", "libmp3lame", "-b:a", f"{bitrate}k", out_mp3])

        after_wav = os.path.join(work, "after.wav")
        decode_channel(out_mp3, 0, after_wav, 16000)
        after_samples, _ = read_wav(after_wav)
        after = level_stats(after_samples, 16000)
        info_in = probe(src)
        info_out = probe(out_mp3)
        return {
            "helper_version": __version__,
            "engine": engine,
            "strength": strength,
            "picked": best["label"],
            "picked_source": best["source"],
            "picked_channel": best["channel"],
            "same_tracks": same,
            "tracks": [{"label": c["label"], "score": c["score"], **c["stats"]} for c in candidates],
            "before": before,
            "after": after,
            "seconds_in": round(min(info_in["duration"], limit_s or info_in["duration"]), 1),
            "seconds_out": round(info_out["duration"], 1),
            "bytes_in": sum(os.path.getsize(s) for s in sources),
            "bytes_out": os.path.getsize(out_mp3),
            "bitrate_kbps": bitrate,
            "work_seconds": round(time.time() - t0, 1),
        }
    finally:
        shutil.rmtree(work, ignore_errors=True)


# ---------------------------------------------------------------- website

class Site:
    def __init__(self, base, key):
        base = base.rstrip("/")
        self.api = base + "/wp-json/ner-michoel/v1/audio-cleanup/worker"
        self.key = key

    def _req(self, method, path, body=None, headers=None, timeout=120):
        h = {"X-NM-Worker-Key": self.key, "User-Agent": "nm-audio-cleanup-helper/" + __version__}
        h.update(headers or {})
        req = urllib.request.Request(self.api + path, data=body, method=method, headers=h)
        try:
            with urllib.request.urlopen(req, timeout=timeout) as r:
                return json.loads(r.read().decode("utf-8") or "null")
        except urllib.error.HTTPError as e:
            text = e.read().decode("utf-8", "replace")[:400]
            raise RuntimeError(f"website said {e.code}: {text}") from None

    def next_job(self):
        return self._req("POST", "/next", b"{}", {"Content-Type": "application/json"}).get("job")

    def fail(self, job_id, message):
        body = json.dumps({"message": message[:900]}).encode("utf-8")
        self._req("POST", f"/{job_id}/fail", body, {"Content-Type": "application/json"})

    def done(self, job_id, mp3_path, report):
        boundary = uuid.uuid4().hex
        with open(mp3_path, "rb") as f:
            data = f.read()
        parts = [
            f"--{boundary}\r\nContent-Disposition: form-data; name=\"report\"\r\n\r\n".encode()
            + json.dumps(report).encode("utf-8") + b"\r\n",
            f"--{boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"cleaned.mp3\"\r\n"
            "Content-Type: audio/mpeg\r\n\r\n".encode() + data + b"\r\n",
            f"--{boundary}--\r\n".encode(),
        ]
        body = b"".join(parts)
        return self._req("POST", f"/{job_id}/done", body,
                         {"Content-Type": f"multipart/form-data; boundary={boundary}"}, timeout=600)


def download(url, dest):
    req = urllib.request.Request(url, headers={"User-Agent": "nm-audio-cleanup-helper/" + __version__})
    with urllib.request.urlopen(req, timeout=600) as r, open(dest, "wb") as f:
        shutil.copyfileobj(r, f, 1 << 20)


def do_job(site, job, model_dir):
    work = tempfile.mkdtemp(prefix="nm-job-")
    try:
        sources = []
        for i, url in enumerate(job["sources"]):
            ext = os.path.splitext(urllib.parse.urlparse(url).path)[1][:6] or ".audio"
            dest = os.path.join(work, f"source{i}{ext}")
            log(f"job {job['id']}: downloading {url}")
            download(url, dest)
            sources.append(dest)
        out = os.path.join(work, "cleaned.mp3")
        report = clean(sources, out, job.get("options") or {}, model_dir)
        log(f"job {job['id']}: cleaned in {report['work_seconds']} s, sending back")
        site.done(job["id"], out, report)
        log(f"job {job['id']}: done")
    except Exception as e:  # noqa: BLE001
        log(f"job {job['id']}: FAILED {e}")
        try:
            site.fail(job["id"], str(e))
        except Exception as e2:  # noqa: BLE001
            log(f"could not tell the website: {e2}")
    finally:
        shutil.rmtree(work, ignore_errors=True)


def main():
    ap = argparse.ArgumentParser(description="Ner Michoel shiur audio cleanup helper")
    ap.add_argument("--site", help="the website address, e.g. https://nermichoel.org/")
    ap.add_argument("--key", default=os.environ.get("NM_CLEANUP_KEY", ""), help="helper key from the Experiments page")
    ap.add_argument("--once", action="store_true", help="do what is waiting, then stop")
    ap.add_argument("--every", type=int, default=60, help="seconds between checks (default 60)")
    ap.add_argument("--model-dir", default=None, help="DeepFilterNet model folder (default: download DeepFilterNet3)")
    ap.add_argument("--file", nargs="+", help="clean local file(s) instead of working for the website")
    ap.add_argument("--out", default="cleaned.mp3")
    ap.add_argument("--strength", default="normal", choices=sorted(STRENGTH_DB))
    ap.add_argument("--minutes", type=float, default=0, help="only the first N minutes (local mode)")
    ap.add_argument("--shorten-silence", action="store_true")
    a = ap.parse_args()
    need_ffmpeg()

    if a.file:
        opts = {"strength": a.strength, "limit_seconds": int(a.minutes * 60), "shorten_silence": a.shorten_silence}
        print(json.dumps(clean(a.file, a.out, opts, a.model_dir), indent=1))
        return
    if not a.site or not a.key:
        ap.error("--site and --key are needed (or --file for local files)")
    site = Site(a.site, a.key)
    log(f"helper {__version__} working for {a.site}")
    while True:
        try:
            job = site.next_job()
        except Exception as e:  # noqa: BLE001
            log(f"could not reach the website: {e}")
            job = None
            if a.once:
                sys.exit(1)
        if job:
            do_job(site, job, a.model_dir)
            continue  # maybe more is waiting
        if a.once:
            log("nothing waiting")
            return
        time.sleep(max(15, a.every))


if __name__ == "__main__":
    main()
