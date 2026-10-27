# Security Policy

## Reporting a vulnerability

Please do not report exploitable security issues in public GitHub issues.

Contact NexorAIQ through the official website at https://www.nexoraiq.tech/ and request a private security-reporting channel. Include a concise description, impact, affected versions, and reproducible steps where safe. Do not include customer data, production credentials, or live API keys.

Please allow the maintainers reasonable time to investigate and coordinate a fix before public disclosure. Do not test against systems or stores you do not own or have explicit permission to assess.

## Deployment guidance

- Keep administrator and private connection credentials out of source control, frontend bundles, screenshots, logs, and issue reports.
- Use least-privilege access and HTTPS for service communication.
- Restrict and authenticate synchronization or administrative endpoints as appropriate to their purpose; do not assume an endpoint is protected solely because it is served by Magento.
- Validate request data, apply suitable rate limits, and avoid exposing internal errors or sensitive customer/catalog data.
- Review access to Magento logs and redact secrets before sharing diagnostics.
- Test configuration and endpoint authorization in a staging environment before production.

This file describes reporting and deployment guidance; it is not a claim that the extension has undergone an independent security audit.
