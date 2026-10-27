"""Serve built assets locally. All API calls in the preview browser are mocked."""
import os,pathlib,subprocess,signal,time,urllib.request
root=pathlib.Path(__file__).resolve().parents[1];os.chdir(root)
p=subprocess.Popen([os.environ.get('PHP','php'),'-S','127.0.0.1:18082','-t','public','tools/router.php'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,start_new_session=True)
try:
 for _ in range(50):
  try:urllib.request.urlopen('http://127.0.0.1:18082/').close();break
  except Exception:time.sleep(.1)
 raise SystemExit(subprocess.run(['node','tests/recovery-accessibility.mjs','http://127.0.0.1:18082']).returncode)
finally:os.killpg(p.pid,signal.SIGTERM);p.wait()
