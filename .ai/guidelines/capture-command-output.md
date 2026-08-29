## Always Capture Command Output

Append `|| true` to every verification command (tests, linting, static analysis, style checks) so the output is captured even when the command fails. Without it a non-zero exit code can swallow the output, forcing a second run just to read the errors.

```bash
# CORRECT — output always visible
vendor/bin/phpunit --filter=testName || true
vendor/bin/pint --test || true

# WRONG — output lost on failure, wastes a run
vendor/bin/phpunit --filter=testName
```
