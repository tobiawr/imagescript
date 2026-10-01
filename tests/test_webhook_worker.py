"""Integration tests: run as root in an isolated PHP/Python Linux container.

Mount the repository read-only at /code and run:
    python3 -B /code/tests/test_webhook_worker.py
Tests use fake tokens and subprocesses; no Google API calls are made.
"""
import concurrent.futures
import fcntl
import importlib.util
import os
from pathlib import Path
import pwd
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('worker', ROOT / 'sync-worker.py')
worker = importlib.util.module_from_spec(spec)
spec.loader.exec_module(worker)


@unittest.skipUnless(sys.platform == 'linux' and os.geteuid() == 0,
                     'Requires an isolated Linux container running as root')
class WebhookWorkerTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.base = Path(self.tmp.name)
        self.base.chmod(0o755)
        self.queue = self.base / 'queue'
        self.queue.mkdir()
        self.web = pwd.getpwnam('www-data')
        os.chown(self.queue, 0, self.web.pw_gid)
        self.queue.chmod(0o770)
        self.token = Path('/tmp/webhook-token')
        self.token.write_text('local-test-token')
        os.chown(self.token, 0, self.web.pw_gid)
        self.token.chmod(0o640)
        self.addCleanup(self.token.unlink, missing_ok=True)

    def notify(self, token='local-test-token', state='change'):
        code = ("register_shutdown_function(function() { echo http_response_code(); });"
                "$_SERVER['HTTP_X_GOOG_CHANNEL_TOKEN'] = getenv('TEST_TOKEN');"
                "$_SERVER['HTTP_X_GOOG_RESOURCE_STATE'] = getenv('TEST_STATE');"
                "require getenv('TEST_HANDLER');")
        r = subprocess.run(['php', '-r', code], user=self.web.pw_uid,
                           group=self.web.pw_gid, capture_output=True, text=True,
                           env=dict(os.environ, TEST_TOKEN=token, TEST_STATE=state,
                                    TEST_HANDLER=str(ROOT / 'webhook.php'),
                                    SYNC_QUEUE_DIR=str(self.queue)), check=True)
        return int(r.stdout)

    def command(self, code='pass'):
        return (sys.executable, '-B', '-c', code)

    def test_original_log_destination_is_denied_to_php_user(self):
        r = subprocess.run(['sh', '-c', 'true > /proc/1/fd/1'],
                           user=self.web.pw_uid, group=self.web.pw_gid,
                           capture_output=True, text=True)
        self.assertNotEqual(r.returncode, 0)
        self.assertIn('Permission denied', r.stderr)

    def test_bad_token_and_initial_handshake_do_not_queue(self):
        self.assertEqual(self.notify(token='wrong'), 403)
        self.assertEqual(self.notify(state='sync'), 200)
        self.assertFalse((self.queue / 'pending').exists())

    def test_queue_permission_failure_returns_503(self):
        self.queue.chmod(0o750)
        self.assertEqual(self.notify(), 503)
        self.assertFalse((self.queue / 'pending').exists())

    def test_burst_coalesces_and_worker_runs_as_root(self):
        with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
            self.assertEqual(list(pool.map(lambda _: self.notify(), range(20))), [200] * 20)
        self.assertEqual([p.name for p in self.queue.iterdir()], ['pending'])
        output = self.base / 'root-owned-output'
        cmd = self.command("import os; from pathlib import Path; assert os.geteuid()==0; "
                           "assert os.environ['SYNC_WAIT_FOR_LOCK']=='1'; "
                           f"Path({str(output)!r}).write_text('synced')")
        self.assertTrue(worker.process_pending(self.queue, cmd))
        self.assertEqual(output.read_text(), 'synced')
        self.assertFalse(worker.process_pending(self.queue, cmd))

    def test_notification_during_sync_is_not_lost(self):
        self.assertEqual(self.notify(), 200)
        cmd = self.command(f"from pathlib import Path; Path({str(self.queue / 'pending')!r}).touch()")
        self.assertTrue(worker.process_pending(self.queue, cmd))
        self.assertTrue((self.queue / 'pending').exists())
        self.assertTrue(worker.process_pending(self.queue, self.command()))
        self.assertEqual(list(self.queue.iterdir()), [])

    def test_failed_sync_retries_and_preserves_new_notifications(self):
        self.assertEqual(self.notify(), 200)
        with self.assertRaises(subprocess.CalledProcessError):
            worker.process_pending(self.queue, self.command('raise SystemExit(1)'))
        self.assertTrue((self.queue / 'processing').exists())
        self.assertEqual(self.notify(), 200)
        self.assertTrue(worker.process_pending(self.queue, self.command()))
        self.assertTrue((self.queue / 'pending').exists())
        self.assertTrue(worker.process_pending(self.queue, self.command()))
        self.assertEqual(list(self.queue.iterdir()), [])

    def test_real_sync_waits_for_lock_but_cron_still_skips(self):
        # Run the real sync entry point. Stop at the first OAuth call, so this
        # tests its lock behavior without contacting Google or changing assets.
        code = ("import runpy, sys, types; "
                "sys.modules['requests']=types.SimpleNamespace(post=lambda *a,**k: sys.exit(42)); "
                f"runpy.run_path({str(ROOT / 'sync.py')!r}, run_name='__main__')")
        env = dict(os.environ, GDRIVE_FOLDER_ID='test', GDRIVE_CLIENT_ID='test',
                   GDRIVE_CLIENT_SECRET='test', GDRIVE_REFRESH_TOKEN='test')
        lock = Path('/tmp/sync.lock')
        self.addCleanup(lock.unlink, missing_ok=True)
        with lock.open('w') as fd:
            fcntl.flock(fd, fcntl.LOCK_EX)
            cron = subprocess.run(self.command(code), env=dict(env, SYNC_WAIT_FOR_LOCK='0'),
                                  capture_output=True, text=True, timeout=5)
            self.assertEqual(cron.returncode, 0)
            self.assertIn('Another sync is already running', cron.stdout)
            proc = subprocess.Popen(self.command(code), env=dict(env, SYNC_WAIT_FOR_LOCK='1'),
                                    stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            try:
                with self.assertRaises(subprocess.TimeoutExpired):
                    proc.wait(timeout=0.5)
                fcntl.flock(fd, fcntl.LOCK_UN)
                proc.communicate(timeout=5)
                self.assertEqual(proc.returncode, 42)
            finally:
                if proc.poll() is None:
                    proc.kill()
                    proc.communicate()


if __name__ == '__main__':
    unittest.main(verbosity=2)
