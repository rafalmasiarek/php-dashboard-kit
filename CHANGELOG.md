# Changelog

## [2.0.0](https://github.com/rafalmasiarek/php-dashboard-kit/compare/v1.4.0...v2.0.0) (2026-09-18)


### ⚠ BREAKING CHANGES

* **dns:** DnsResolverInterface adds resolveAAAA() and resolve(); existing implementers must add both methods. DnsAnswer's constructor gains a third parameter (authenticatedData), which is backward compatible for positional construction but changes the class shape.

### Features

* **dns:** add IPv6 resolution and DNSSEC AD-bit reporting ([#19](https://github.com/rafalmasiarek/php-dashboard-kit/issues/19)) ([05792e3](https://github.com/rafalmasiarek/php-dashboard-kit/commit/05792e3971acc2ed57b1300e5788799d2eb46760))

## [1.4.0](https://github.com/rafalmasiarek/php-dashboard-kit/compare/v1.3.0...v1.4.0) (2026-09-17)


### Features

* add DNS resolver interface and DNS-aware HTTP client ([f5a004e](https://github.com/rafalmasiarek/php-dashboard-kit/commit/f5a004e9d937fce7979bb392a3daae102d49634b))
* **dns,http:** add pluggable DNS resolver interface and a DNS-aware curl client ([5df0438](https://github.com/rafalmasiarek/php-dashboard-kit/commit/5df0438371c8430a0fb81b98738fdd1c2af030ed))

## [1.3.0](https://github.com/rafalmasiarek/php-dashboard-kit/compare/v1.2.0...v1.3.0) (2026-09-16)


### Features

* **csrf:** apply configured container defaults via setDefaults() ([#15](https://github.com/rafalmasiarek/php-dashboard-kit/issues/15)) ([f385014](https://github.com/rafalmasiarek/php-dashboard-kit/commit/f3850147c24c874ca39487fa8d09f12ad1d93078))

## [1.2.0](https://github.com/rafalmasiarek/php-dashboard-kit/compare/v1.1.0...v1.2.0) (2026-09-16)


### Features

* **csrf:** wire real client IP through generation and validation ([1f30f8c](https://github.com/rafalmasiarek/php-dashboard-kit/commit/1f30f8c6caedb591e8d9f87a6035aba03c664024))
* **csrf:** wire real client IP through generation and validation ([5b556e6](https://github.com/rafalmasiarek/php-dashboard-kit/commit/5b556e68f9aedc727a73c3753c53a855da429420))

## [1.1.0](https://github.com/rafalmasiarek/php-dashboard-kit/compare/v1.0.4...v1.1.0) (2026-09-16)


### Features

* flash messages for login_failed, password/email/profile updates, and inactivity logout ([7cde362](https://github.com/rafalmasiarek/php-dashboard-kit/commit/7cde362bcb3a4d2749d2d32767414a79c46262a0))
* flash messages for login_failed, password/email/profile updates, and inactivity logout ([00542bf](https://github.com/rafalmasiarek/php-dashboard-kit/commit/00542bfcaa0e2c940d9fb51498db1e9a5ee1cbf8))


### Miscellaneous Chores

* **deps:** pin authkit minimum to 2.1.2 ([90b1ed9](https://github.com/rafalmasiarek/php-dashboard-kit/commit/90b1ed91d40f5e44311d1edd37cd71ec28f5a841))

## [1.0.4](https://github.com/rafalmasiarek/php-dashboard-kit/compare/v1.0.3...v1.0.4) (2026-09-15)


### Miscellaneous Chores

* **deps:** require real-ip-resolver ^2.3 ([74c14e3](https://github.com/rafalmasiarek/php-dashboard-kit/commit/74c14e3cce56cf90f0f1046039e80ebb574d2692))
* **deps:** require real-ip-resolver ^2.3 ([d1e0fa8](https://github.com/rafalmasiarek/php-dashboard-kit/commit/d1e0fa8f88bc04abafd01b528216e072b11834ac))

## [1.0.3](https://github.com/rafalmasiarek/php-dashboard-kit/compare/v1.0.2...v1.0.3) (2026-09-13)


### Bug Fixes

* create SQLite storage directory if missing ([6d12181](https://github.com/rafalmasiarek/php-dashboard-kit/commit/6d12181877f72f558a11036e15f8766297e95bcc))
* create SQLite storage directory if missing ([7c1f048](https://github.com/rafalmasiarek/php-dashboard-kit/commit/7c1f048b4150775013a371cccaff1be52ffc469e))

## [1.0.2](https://github.com/rafalmasiarek/php-dashboard-kit/compare/v1.0.1...v1.0.2) (2026-09-13)


### Miscellaneous Chores

* sync shared config ([#5](https://github.com/rafalmasiarek/php-dashboard-kit/issues/5)) ([8ace2d2](https://github.com/rafalmasiarek/php-dashboard-kit/commit/8ace2d2a0af1a56edec3618a089e30b5bbb73378))

## [1.0.1](https://github.com/rafalmasiarek/php-dashboard-kit/compare/v1.0.0...v1.0.1) (2026-09-13)


### Miscellaneous Chores

* bootstrap release-please manifest ([c55c58c](https://github.com/rafalmasiarek/php-dashboard-kit/commit/c55c58cbec113253865b607e5b2f88bd4465b625))
* **deps:** update php-di/php-di requirement from ^6.4 to ^6.4 || ^7.0 ([#2](https://github.com/rafalmasiarek/php-dashboard-kit/issues/2)) ([49b63bb](https://github.com/rafalmasiarek/php-dashboard-kit/commit/49b63bbf5ae1ab68a166732854e442032fd53b96))
* set release-please manifest to 1.0.0 ([63a4010](https://github.com/rafalmasiarek/php-dashboard-kit/commit/63a4010e145363b80ffeef5410cf24e6fee2a76f))
* sync shared config ([#1](https://github.com/rafalmasiarek/php-dashboard-kit/issues/1)) ([e720d7e](https://github.com/rafalmasiarek/php-dashboard-kit/commit/e720d7e7e6ef0560f0cad9ac66719f93fe6993e5))
* sync shared config ([#3](https://github.com/rafalmasiarek/php-dashboard-kit/issues/3)) ([9bf1fa6](https://github.com/rafalmasiarek/php-dashboard-kit/commit/9bf1fa66fcd485d1e4a457b91a8fa6401fd8e48b))
