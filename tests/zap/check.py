#!/usr/bin/env python3
"""Decides the result of the ZAP scans (see scan.sh): fails when a report holds a finding of risk medium or higher
that is not listed in rules.tsv as IGNORE, INFO or WARN with a written reason, or any finding listed as FAIL. A line
of rules.tsv stands for one finding: the rule id together with the name of the alert as the report shows it, so
one rule with several alerts (e.g. 10055, the content security policy) needs a line for each accepted alert.
Usage: check.py rules.tsv report.json [report.json ...]"""
import json
import sys

RISKS = {0: 'info', 1: 'low', 2: 'medium', 3: 'high'}


def load_rules(path):
    rules, errors = {}, []
    with open(path, encoding='utf-8') as f:
        for number, line in enumerate(f, 1):
            if line.startswith('#') or not line.strip():
                continue
            parts = [p.strip() for p in line.rstrip('\n').split('\t')]
            if len(parts) != 4 or parts[1] not in ('IGNORE', 'INFO', 'WARN', 'FAIL') or not parts[0].isdigit():
                errors.append('line %d: expected "id<TAB>IGNORE|INFO|WARN|FAIL<TAB>name<TAB>reason"' % number)
            elif parts[1] != 'FAIL' and len(parts[3]) < 20:
                errors.append('line %d: rule %s needs a written reason in the fourth column' % (number, parts[0]))
            elif (parts[0], parts[2]) in rules:
                errors.append('line %d: rule %s "%s" is listed twice' % (number, parts[0], parts[2]))
            else:
                rules[(parts[0], parts[2])] = parts[1]
    return rules, errors


def main(argv):
    if len(argv) < 3:
        print(__doc__)
        return 2
    rules, errors = load_rules(argv[1])
    for error in errors:
        print('ERROR %s: %s' % (argv[1], error))
    found = {}
    for path in argv[2:]:
        with open(path, encoding='utf-8') as f:
            report = json.load(f)
        for site in report.get('site', []):
            for alert in site.get('alerts', []):
                key = (int(alert['riskcode']), alert['pluginid'], alert['name'])
                found.setdefault(key, []).extend(i.get('uri', '') for i in alert.get('instances', []))
    failed = bool(errors)
    for (risk, rule, name), uris in sorted(found.items(), reverse=True):
        level = rules.get((rule, name))
        fails = level == 'FAIL' or (risk >= 2 and level is None)
        failed = failed or fails
        print('%-6s %-7s %-6s %s (%d): %s' % ('FAIL' if fails else 'ok', RISKS.get(risk, risk), rule, name, len(uris),
                                              'not listed in rules.tsv' if level is None else 'listed as ' + level))
        if fails:
            for uri in sorted(set(uris))[:5]:
                print('         ' + uri)
    for rule, name in sorted(set(rules) - {(rule, name) for (_, rule, name) in found}):
        print('note: %s "%s" is listed in rules.tsv but was not found in this run' % (rule, name))
    print('FAILED: findings of risk medium or higher that are not listed with a reason' if failed else 'OK')
    return 1 if failed else 0


if __name__ == '__main__':
    sys.exit(main(sys.argv))
