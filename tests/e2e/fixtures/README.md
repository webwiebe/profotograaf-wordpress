# E2E fixtures

`embed.js` is the platform's gallery script, built from the commit in
`embed.js.commit`. The mock platform serves it to the browser, so the E2E tests
draw real galleries. Do not edit it by hand. Refresh it with
`scripts/refresh-embed-fixture.sh <platform checkout>` and commit both files.

`cf7-setup.php` configures Contact Form 7 for the leads tests.
