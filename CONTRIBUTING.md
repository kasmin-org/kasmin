# Contributing to Kasmin

Thank you for helping! Kasmin is free software under the AGPL-3.0.

## Language

Issues and pull requests are welcome in **German or English**.

## AI-assisted contributions

Issues and pull requests written with the help of AI are **welcome and allowed**. Please mention it in the
description. They are reviewed exactly like any other contribution. Kasmin itself is developed with heavy use of AI
(see the README).

## How we review

- Every pull request is **read and reviewed before any of its code is run**. For first-time and external
  contributors, CI workflows start only after a maintainer has approved them.
- Changes to `.github/workflows/`, `Dockerfile`, `composer.json`/`composer.lock`, `package*.json`, install scripts
  or the installer get an extra security review.
- Security problems: please do **not** open a public issue – see [SECURITY.md](SECURITY.md).

## Branches and pull requests

- Branch from `develop`: `feature/<topic>` or `issue-<number>-<topic>`. Pull requests go to `develop`;
  `main` only receives releases.
- Give the pull request a clear title and describe **what** changes, **why**, and **how you tested** it; add
  screenshots (phone and desktop) for UI changes and link the issue.
- Update the documentation in `docs/`, the `CHANGELOG.md` (section "Unreleased") and translations (German and
  English) in the same pull request.

## Product rules

- Kasmin is generic: no organisation-specific names, prices or texts in code or docs – use configuration and demo
  data.
- Use "legal guardians" (German: "Erziehungsberechtigte"), never "parents"; prefer gender-neutral wording.
- Every page must work on phones and desktops.

## Development setup

See [docs/development/setup.md](docs/development/setup.md). Before pushing, run the tests, the code style check and
static analysis as described there.

## Versioning

Kasmin follows Semantic Versioning, see [docs/development/versioning.md](docs/development/versioning.md).
