# Security Policy

## Supported Versions

The current development version is supported during active development.

## Reporting a Vulnerability

Please report security issues privately to the maintainer instead of opening a public issue.

Include:

- A clear description of the issue.
- Steps to reproduce.
- Impact and affected versions.
- Any suggested fix, if available.

## Security Commitments

- No telemetry or tracking.
- No hidden HTTP requests.
- No external services without explicit administrator configuration.
- Capability checks for privileged actions.
- Nonce checks for state-changing requests.
- Sanitization on input and escaping on output.
- No database writes during bootstrap.
