# Changelog

## [4.8.0](https://github.com/rafalmasiarek/php-dashboard-kit/compare/v4.7.0...v4.8.0) (2026-10-05)


### Features

* **clock:** add richer timezone-aware ClockInterface, TimezoneClock ([#59](https://github.com/rafalmasiarek/php-dashboard-kit/issues/59)) ([91f4ef0](https://github.com/rafalmasiarek/php-dashboard-kit/commit/91f4ef07d3637ff03a4f3bde25c5817b68f8a1c0))


### Bug Fixes

* **clock:** simplify TimezoneClock to a zero-config default ([#60](https://github.com/rafalmasiarek/php-dashboard-kit/issues/60)) ([d1554eb](https://github.com/rafalmasiarek/php-dashboard-kit/commit/d1554eb86d4708fd8cda501826f82f55b0a4cf22))

## [4.7.0](https://github.com/rafalmasiarek/php-dashboard-kit/compare/v4.6.1...v4.7.0) (2026-10-05)


### Features

* **clock:** add PSR-20 ClockInterface, remove scattered raw DateTimeImmutable ([#57](https://github.com/rafalmasiarek/php-dashboard-kit/issues/57)) ([6b68154](https://github.com/rafalmasiarek/php-dashboard-kit/commit/6b68154391e761e6834c401e8846fbbaf6a4beff))

## [4.6.1](https://github.com/rafalmasiarek/php-dashboard-kit/compare/v4.6.0...v4.6.1) (2026-10-05)


### Bug Fixes

* **schema:** move timestamps/soft_deletes defaults to a separate config key ([#54](https://github.com/rafalmasiarek/php-dashboard-kit/issues/54)) ([4bba194](https://github.com/rafalmasiarek/php-dashboard-kit/commit/4bba194c87599649e393190f73546d6589150c44))

## [4.6.0](https://github.com/rafalmasiarek/php-dashboard-kit/compare/v4.5.0...v4.6.0) (2026-10-05)


### Features

* **model:** add max()/min()/sum() aggregates and joinOn()/leftJoinOn() ([#51](https://github.com/rafalmasiarek/php-dashboard-kit/issues/51)) ([4c526fb](https://github.com/rafalmasiarek/php-dashboard-kit/commit/4c526fbe362891a7618467423e6ca014f8dfdf20))
* **schema:** make timestamps/soft_deletes defaults app-configurable ([#52](https://github.com/rafalmasiarek/php-dashboard-kit/issues/52)) ([dc7967d](https://github.com/rafalmasiarek/php-dashboard-kit/commit/dc7967d42994c4a8d188219f75108a10c41d801c))

## [4.5.0](https://github.com/rafalmasiarek/php-dashboard-kit/compare/v4.4.0...v4.5.0) (2026-10-05)


### Features

* **model:** add whereRaw() escape hatch for OR/subquery conditions ([#49](https://github.com/rafalmasiarek/php-dashboard-kit/issues/49)) ([48f0eee](https://github.com/rafalmasiarek/php-dashboard-kit/commit/48f0eee0ce8875b8bb6f7a8dd2971a0f42081843))

## [4.4.0](https://github.com/rafalmasiarek/php-dashboard-kit/compare/v4.3.1...v4.4.0) (2026-10-05)


### Features

* **model:** support Raw values in QueryBuilder::update() ([#47](https://github.com/rafalmasiarek/php-dashboard-kit/issues/47)) ([c3e4710](https://github.com/rafalmasiarek/php-dashboard-kit/commit/c3e47101324e98298ac0e97474d43655a0e4d859))

## [4.3.1](https://github.com/rafalmasiarek/php-dashboard-kit/compare/v4.3.0...v4.3.1) (2026-10-05)


### Bug Fixes

* **model:** correct PostgreSQL syntax in upsert()/insertOrIgnore() ([#45](https://github.com/rafalmasiarek/php-dashboard-kit/issues/45)) ([2b64688](https://github.com/rafalmasiarek/php-dashboard-kit/commit/2b64688b3bbd3149449bbb5bb874849cc51071ac))

## [4.3.0](https://github.com/rafalmasiarek/php-dashboard-kit/compare/v4.2.0...v4.3.0) (2026-10-05)


### Features

* **model:** add QueryBuilder::upsert()/insertOrIgnore() for ad-hoc tables ([#43](https://github.com/rafalmasiarek/php-dashboard-kit/issues/43)) ([f41b4cb](https://github.com/rafalmasiarek/php-dashboard-kit/commit/f41b4cb8224f7d6a948b63ce9b3aebd126a6a2c2))

## [4.2.0](https://github.com/rafalmasiarek/php-dashboard-kit/compare/v4.1.1...v4.2.0) (2026-10-05)


### Features

* **model:** add QueryBuilder::select() for explicit column lists ([#41](https://github.com/rafalmasiarek/php-dashboard-kit/issues/41)) ([b5d2b5d](https://github.com/rafalmasiarek/php-dashboard-kit/commit/b5d2b5db1157efe5acdc60ff6175f6ebc4ab0ec1))

## [4.1.1](https://github.com/rafalmasiarek/php-dashboard-kit/compare/v4.1.0...v4.1.1) (2026-10-05)


### Bug Fixes

* **model:** add QueryBuilder::insert() for Model::on() tables ([#39](https://github.com/rafalmasiarek/php-dashboard-kit/issues/39)) ([e17d1bf](https://github.com/rafalmasiarek/php-dashboard-kit/commit/e17d1bfe75876ed4103775544f2569c722c1280c))

## [4.1.0](https://github.com/rafalmasiarek/php-dashboard-kit/compare/v4.0.1...v4.1.0) (2026-10-05)


### Features

* **model:** add upsert, bulk update/delete, joins, raw expressions, dynamic tables ([#37](https://github.com/rafalmasiarek/php-dashboard-kit/issues/37)) ([fc141b2](https://github.com/rafalmasiarek/php-dashboard-kit/commit/fc141b2bff03a11c01603be52bc261dca67ceb1f))

## [4.0.1](https://github.com/rafalmasiarek/php-dashboard-kit/compare/v4.0.0...v4.0.1) (2026-10-04)


### Bug Fixes

* **composer:** remove unused http-client requirement ([#35](https://github.com/rafalmasiarek/php-dashboard-kit/issues/35)) ([1b98025](https://github.com/rafalmasiarek/php-dashboard-kit/commit/1b98025a7cb589891d72de31dd14f4fb48b2d123))

## [4.0.0](https://github.com/rafalmasiarek/php-dashboard-kit/compare/v3.0.0...v4.0.0) (2026-10-02)


### ⚠ BREAKING CHANGES

* **http:** rafalmasiarek\DashboardKit\Http\* (except RequestTimingMiddleware, SafeErrorHandler, HttpMessagePicker) and rafalmasiarek\DashboardKit\Dns\* no longer exist. Require rafalmasiarek/http-client ^1.0 and update imports to rafalmasiarek\HttpClient\* / rafalmasiarek\HttpClient\Dns\*.

### Features

* **http:** use rafalmasiarek/http-client instead of bundled src/Http and src/Dns ([#32](https://github.com/rafalmasiarek/php-dashboard-kit/issues/32)) ([29fafbb](https://github.com/rafalmasiarek/php-dashboard-kit/commit/29fafbba94b65e01e0492632fa5b08a3d3ca8221))
* **model:** add timestamps, soft deletes, and pruning to Model ([#33](https://github.com/rafalmasiarek/php-dashboard-kit/issues/33)) ([bb31c2e](https://github.com/rafalmasiarek/php-dashboard-kit/commit/bb31c2e182304a602b20d5a8307b002da64e3de5))

## [3.0.0](https://github.com/rafalmasiarek/php-dashboard-kit/compare/v2.4.0...v3.0.0) (2026-10-01)


### ⚠ BREAKING CHANGES

* **http:** HttpClientInterface::request() returns HttpResponseInterface instead of the concrete HttpResponse class. Property access ($response->statusCode, ->error, ->body) no longer works — use the equivalent methods instead (getStatusCode(), getError(), getContent()). HttpResponse still exists, as a simple eager implementation of the new interface (useful for stubs/tests/synthetic responses), but CurlHttpClient no longer returns it for a real request — RetryStrategyInterface and ChunkedFileDownloader (and any other code type-hinting the old concrete class) must be updated to accept HttpResponseInterface.

### Features

* **http:** lazy, concurrent HTTP client, SSE, caching, throttling ([#30](https://github.com/rafalmasiarek/php-dashboard-kit/issues/30)) ([823868f](https://github.com/rafalmasiarek/php-dashboard-kit/commit/823868fa65175223aeae328282fedfee937e04b1))

## [2.4.0](https://github.com/rafalmasiarek/php-dashboard-kit/compare/v2.3.0...v2.4.0) (2026-09-27)


### Features

* **http:** expose curl transport timing/target via HttpResponse ([#28](https://github.com/rafalmasiarek/php-dashboard-kit/issues/28)) ([384cacc](https://github.com/rafalmasiarek/php-dashboard-kit/commit/384caccd1072d6222c9c93e6eba62f90b29afe1b))

## [2.3.0](https://github.com/rafalmasiarek/php-dashboard-kit/compare/v2.2.0...v2.3.0) (2026-09-19)


### Features

* **schema:** support PRAGMA statements for module sqlite config ([313dd88](https://github.com/rafalmasiarek/php-dashboard-kit/commit/313dd88ae640ed29cf2529685b1473123123ba8b))
* support PRAGMA statements for module sqlite config ([4d05283](https://github.com/rafalmasiarek/php-dashboard-kit/commit/4d05283b23a32194916525726e3e68cd79392a26))

## [2.2.0](https://github.com/rafalmasiarek/php-dashboard-kit/compare/v2.1.0...v2.2.0) (2026-09-19)


### Features

* **http:** add centralized retry decorator for HTTP requests ([71ae5e4](https://github.com/rafalmasiarek/php-dashboard-kit/commit/71ae5e4cd951d59769370b9b685516317e62a11b))
* resumable-download safety, centralized retry, and redirect security hardening ([c5e1221](https://github.com/rafalmasiarek/php-dashboard-kit/commit/c5e1221bcdcbeb97a562231f46d3a848b4b57cf0))


### Bug Fixes

* **http:** strip credentials on cross-origin redirects, guard against private network targets ([b35312b](https://github.com/rafalmasiarek/php-dashboard-kit/commit/b35312b3a800e48b0a91f4e5217696e03c52ae7f))
* **http:** validate resumed downloads before appending to avoid corruption ([883d12d](https://github.com/rafalmasiarek/php-dashboard-kit/commit/883d12de80888868559d5038d68bdb62fd7afa1f))

## [2.1.0](https://github.com/rafalmasiarek/php-dashboard-kit/compare/v2.0.0...v2.1.0) (2026-09-19)


### Features

* **log:** redact registered secrets from every log channel ([2e6706b](https://github.com/rafalmasiarek/php-dashboard-kit/commit/2e6706b1f62ec1f1057a61f520e6f0e9d8982dad))

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
