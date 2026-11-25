# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [2.0.0] - 25 November 2026
### Added
- Admin ACL resource for the module configuration section
- README documenting the configuration and the rules for which orders get invoiced

### Changed
- BREAKING: automatic invoicing now has its own setting (Stores > Configuration > Yireo > Yireo Auto Invoice Zero Subtotal Orders > Enabled), disabled by default, instead of following the "Automatically Invoice All Items" option of the Zero Subtotal Checkout payment method; enable it again after upgrading
- The cron job now runs every 5 minutes instead of every minute

### Fixed
- Orders with a zero subtotal but a non-zero grand total (for instance shipping costs), or paid with another method than the free payment method, were invoiced as paid offline; now only free payment orders with a zero grand total are invoiced
- Held, already invoiced or otherwise non-invoiceable orders are no longer invoiced
- The enable setting is now respected per store
- A single failing order no longer stops the remaining orders from being invoiced, and results are logged
- Large numbers of orders are processed in pages and concurrent cron runs are prevented by a lock
- Orders with a missing grand total are no longer treated as zero-total orders
- Missing composer dependencies (Magento_Backend, Magento_Config, Magento_Store, psr/log) are now declared
