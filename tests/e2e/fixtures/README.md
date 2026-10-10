# E2E fixtures

`embed.js` is the platform's gallery script, built from the commit in
`embed.js.commit`. The mock platform serves it to the browser, so the E2E tests
draw real galleries. Do not edit it by hand. Refresh it with
`scripts/refresh-embed-fixture.sh <platform checkout>` and commit both files.

`embed-legacy.js` is the embed.js from before the state contract
(professionals#2338, built from platform commit e3dec18). It sets
`data-pf-ready` and no `data-pf-state`. The specs route it in to test the
fallback for older scripts. It stays as it is when `embed.js` is refreshed.

`cf7-setup.php` configures Contact Form 7 for the leads tests.

`photo.png` is the image the mock platform serves for every variant of its PNG
gallery (`g-e2e-png`), the case of wiebe-xyz/professionals#2330.
