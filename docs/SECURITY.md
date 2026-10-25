# WP DesignChecker — Security Documentation

## 1. Overview

WP DesignChecker is a visual regression testing application consisting of a WordPress administration plugin and a Python FastAPI backend.

The application handles authenticated API requests, website screenshots, scan results, and historical scan records.

Security is implemented across both components to reduce unauthorized access and protect application data.

**Release scope:** The initial version is designed for controlled local development and testing. It has not undergone an independent security audit and should not be considered production-hardened.

## 2. Security Architecture

The application uses two layers of access control:

1. **WordPress access control:** Restricts administrative operations and screenshot access to authorized WordPress users.
2. **FastAPI authentication:** Requires an API key for protected requests to the Python scanning service.

The WordPress PHP API client communicates with FastAPI on behalf of authorized administrators.

The API key is not intentionally exposed to the browser-side JavaScript application.

### Authentication Flow

```text
WordPress Administrator
          |
          v
WordPress Dashboard
          |
          | Capability checks and nonce verification
          v
WordPress PHP API Client
          |
          | X-API-Key
          v
FastAPI Authentication
          |
          v
Protected Scanning Operations
          |
          +--> Scan execution
          +--> Scan history
          +--> Screenshot retrieval
```

## 3. API Key Authentication

The Python backend protects its scanning endpoints using API-key authentication.

The WordPress plugin sends the configured key in the HTTP request header:

```http
X-API-Key: <configured-secret>
```

Requests without a valid API key are rejected.

The WordPress API client reads the key from the `WP_DESIGNCHECKER_API_KEY` constant.

### Configuration Guidelines

- Generate a long, unpredictable API key.
- Configure the same key in WordPress and FastAPI.
- Never hardcode a real key into source files intended for public distribution.
- Never expose the key through JavaScript or HTML.
- Never commit `.env` files containing secrets.
- Replace a key immediately if it is accidentally disclosed.

API keys should be stored in environment-specific configuration rather than shared repository files.

## 4. WordPress Authorization

Administrative access is restricted using WordPress capability checks.

The plugin uses the `manage_options` capability for privileged dashboard operations.

This limits administrative functionality to users with the required WordPress permissions.

WordPress permissions must be checked server-side. Hiding a button in the browser is not sufficient authorization.

## 5. Nonce Verification

WP DesignChecker uses WordPress nonces to help protect administrative requests against cross-site request forgery.

Nonce verification is used for operations such as scan submission and screenshot retrieval.

The application uses WordPress functions including:

- `check_admin_referer()`
- `check_ajax_referer()`
- `wp_create_nonce()`

Nonces supplement authentication and authorization; they do not replace permission checks.

## 6. Screenshot Access Protection

Screenshot endpoints return image data through authenticated server-side requests.

The browser requests an image from a WordPress AJAX endpoint.

WordPress verifies the user's permissions and nonce before retrieving the image from FastAPI.

This allows screenshot previews to work without sending the Python API key directly to the browser.

The Python API client validates screenshot selections against supported modes and viewports.

Supported viewports:

- Desktop.
- Tablet.
- Mobile.

Supported screenshot modes include approved baselines, current screenshots, and visual difference images.

Historical screenshot requests additionally validate the scan identifier.

## 7. Input Validation

The PHP API client validates scan identifiers using a UUID-format check.

Screenshot modes and viewports are restricted to predefined values.

The Python API validates incoming parameters and rejects unsupported requests.

These controls reduce the risk of malformed input reaching internal file paths or database operations.

Input validation must continue to be applied whenever new API endpoints or configuration options are introduced.

## 8. Database Security

WP DesignChecker uses SQLite to store scan records.

Database operations use parameterized SQL statements rather than concatenating untrusted values into SQL queries.

The database stores:

- Scan identifiers.
- Execution states.
- Comparison results.
- Error information.
- Creation timestamps.
- Completion timestamps.

The SQLite database is stored in the Python application directory.

The initial release does not include multi-user database permissions or encryption-at-rest functionality.

The database file should not be publicly served by a web server.

## 9. Screenshot File Validation

The WordPress API client validates image responses before returning them to the dashboard.

Current checks include:

- Successful HTTP response status.
- Nonempty image content.
- Maximum image size of 10 MB.
- PNG file signature validation.

The plugin serves images with a PNG content type.

The screenshot proxy also uses headers intended to discourage caching and prevent MIME-type sniffing.

These checks reduce accidental delivery of unexpected content, but PNG signature validation alone does not establish that an image is free of malicious or malformed content.

## 10. Local Service and Network Security

The initial Python service is configured to run at:

```text
http://127.0.0.1:8000
```

Binding the service to the loopback interface restricts ordinary remote network access.

However, the service uses HTTP rather than HTTPS.

This configuration is intended for a trusted local development environment.

**Do not expose the FastAPI service directly to the public internet using the current development configuration.**

A future remote deployment would require additional protections, including secure transport, network restrictions, secret management, and deployment-specific security testing.

## 11. Security Testing

Manual security checks were performed during development.

Verified behaviors included:

| Security test | Result |
|---|---|
| Missing API key | Rejected |
| Invalid API key | Rejected |
| Invalid scan identifier | Handled safely |
| Missing WordPress nonce | Denied |
| Invalid WordPress nonce | Denied |
| Invalid historical screenshot nonce | Denied |
| Unauthorized administrative access | Denied |
| Valid authorized screenshot access | Worked correctly |
| Git secret tracking checks | No tracked local secret file reported during the check |

Automated FastAPI tests also verified authentication and selected invalid-request responses.

These results demonstrate that the tested controls behaved as expected. They do not establish complete protection against all attack techniques.

## 12. Security Limitations

The initial release has several security limitations:

- API authentication uses a shared secret rather than individual service identities.
- The local API connection does not use HTTPS.
- API key rotation is a manual process.
- No dedicated rate-limiting mechanism has been documented.
- No comprehensive automated WordPress security test suite has been implemented.
- No independent penetration test has been performed.
- The Python service is not designed for unrestricted public exposure.
- Screenshot capture targets a configured local website rather than arbitrary user-supplied URLs.

These limitations should be reviewed before expanding the application beyond controlled local development.

## 13. Security Recommendations for Future Releases

Future development should consider:

1. Configurable API endpoints with secure HTTPS connections.
2. Stronger service authentication and key rotation procedures.
3. Rate limiting and request monitoring.
4. Automated WordPress security testing.
5. Security review of configurable screenshot target URLs.
6. Dependency vulnerability scanning.
7. Safer production deployment and process management.
8. More detailed error logging without exposing secrets.

## 14. Reporting Security Issues

If a security vulnerability is discovered, avoid publicly sharing exploit details, API keys, or sensitive configuration information.

For the initial development release, security issues should be reported privately to the project maintainer through an appropriate contact channel.

A dedicated vulnerability reporting process may be introduced before broader public distribution.

## 15. Security Summary

WP DesignChecker implements API-key authentication, WordPress capability checks, nonce verification, input validation, parameterized database queries, and screenshot response validation.

Manual and automated tests have verified several of these controls.

The current security model is suitable for the application's controlled local development scope, subject to the limitations documented above.

Production deployment requires additional security assessment and hardening.