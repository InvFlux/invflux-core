# InvFlux Core

`nandan108/invflux-core` is the domain package that sits between:
- `nandan108/slot-flow`
- a persistence adapter such as MySQL
- a platform adapter such as WooCommerce

It defines:
- inventory-facing contracts
- declarative slot-space schema objects
- write-side command objects
- persistence result and conflict DTOs
- the projection SPI used by trusted in-transaction participants

It does not implement SQL, locking, transactions, or any concrete backend.

## Documentation

Start here:

- [Domain Model](docs/invflux-domain-model.md)
- [Contracts](docs/contracts.md)
- [Idempotency](docs/idempotency.md)

## Installation

```bash
composer require nandan108/invflux-core
```

## Package Shape

- [`src/Contracts`](src/Contracts)
- [`src/Idempotency`](src/Idempotency)
- [`src/Schema`](src/Schema)
- [`src/Mutation`](src/Mutation)
- [`src/Results`](src/Results)
- [`src/Projection`](src/Projection)
- [`src/ReadModels`](src/ReadModels)
- [`src/Registry`](src/Registry)

## Licensing

InvFlux Core is dual-licensed:

- `GPL-2.0-or-later`
- a separate commercial license for proprietary or otherwise non-GPL-compatible use

See [LICENSE](LICENSE), [LICENSE-GPL-2.0-or-later](LICENSE-GPL-2.0-or-later), and [LICENSE-InvFlux-Commercial](LICENSE-InvFlux-Commercial).
