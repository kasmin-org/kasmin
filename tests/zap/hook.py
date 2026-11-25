"""Hooks for zap-baseline.py (see scan.sh): more start addresses than the one target, and the session of a logged-in
administrator for the second run. Variables: ZAP_SEEDS (paths, separated by spaces), ZAP_COOKIE (value of the Cookie
header; empty for the public run), ZAP_MARKER (text that only a logged-in page contains), ZAP_SKIP (ids of passive
rules that do not run, separated by commas)."""
import os
import time

SEEDS = os.environ.get('ZAP_SEEDS', '').split()
COOKIE = os.environ.get('ZAP_COOKIE', '')
MARKER = os.environ.get('ZAP_MARKER', '')
SKIP = os.environ.get('ZAP_SKIP', '')


def _base(target):
    return target[:target.index('/', 8)] if target.count('/') > 2 else target


def _logged_in(zap, target, when):
    if COOKIE and MARKER and MARKER not in zap.urlopen(_base(target) + '/admin'):
        raise IOError('The session is not logged in %s: /admin does not contain "%s"' % (when, MARKER))


def zap_started(zap, target):
    if SKIP:
        zap.pscan.disable_scanners(SKIP)
    if COOKIE:
        # Every request carries the session of the administrator; the scanner keeps no cookies of its own.
        zap.replacer.add_rule(description='session', enabled=True, matchtype='REQ_HEADER', matchregex=False,
                              matchstring='Cookie', replacement=COOKIE)
        zap.spider.set_option_accept_cookies(False)
        # Forms are read, not sent: sending the logout form would end the session, other forms change data.
        zap.spider.set_option_post_form(False)
        _logged_in(zap, target, 'at the start')


def zap_spider(zap, target):
    for seed in SEEDS:
        scan = zap.spider.scan(_base(target) + seed)
        if not str(scan).isdigit():
            raise IOError('The spider did not start for %s: %s' % (seed, scan))
        while int(zap.spider.status(scan)) < 100:
            time.sleep(2)


def zap_pre_shutdown(zap):
    urls = sorted(zap.core.urls())
    with open('/zap/wrk/urls.txt', 'w') as f:
        f.write('\n'.join(urls) + '\n')
    if urls:
        _logged_in(zap, urls[0], 'at the end')
