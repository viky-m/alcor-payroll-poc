# Payroll manual adjustments PoC

A small PHP 8.3 domain model for tracking manual corrections to a system-calculated earning line. The solution uses a simple object-oriented aggregate and JSON snapshots rather than CQRS or Event Sourcing: this keeps the exercise easy to run and understand while retaining the complete adjustment history.

## Run

With Docker and Docker Compose installed:

```sh
make build
make install
make test
make analyse
make demo
```

The implementation was developed in TDD cycles: write a failing PHPUnit test, implement the behavior, then refactor and rerun tests and PHPStan. Unit tests cover the domain; integration tests cover the filesystem and repeat CLI runs.

## Model and assumptions

- `Money` stores integer USD cents, not floating-point dollars. For example, `$1,050.00` is `105000` cents and `-$45.55` is `-4555` cents; addition rejects integer overflow. Formatting is a CLI concern. Currency conversion is outside this PoC.
- `EarningLine` can be recalculated repeatedly until its first manual adjustment. Thereafter its system value is frozen and subsequent recalculations are ignored. Current value is the system value plus the ordered adjustments.
- `Adjustment` is immutable and requires a nonzero signed amount, a nonblank comment (trimmed at the edges), and an explicitly supplied immutable timestamp. A negative final value is allowed. To correct a mistake, append a compensating adjustment; existing entries cannot be edited or removed through the model.

## Persistence and limitations

`FileEarningLineRepository` stores each line as a versioned JSON snapshot containing a revision, `system_cents`, and the ordered adjustments (`amount_cents`, `comment`, `occurred_at`). The current value is calculated, not stored. `load()` returns the line and revision or `null` if absent; invalid files cause an explicit error. `save()` requires `null` to create a line or the previously loaded revision to update it.

Writers use a stable per-line lock, reread and validate the snapshot, check its revision, frozen base and history prefix, and then replace it atomically using a temporary file in the same directory. Readers use the same lock. Stale writes and changes or deletions of existing adjustments are rejected.

This is a local snapshot store, not an event store or a tamper-proof audit log. Its protections apply only to cooperating processes using this repository: direct file changes/deletion, malicious access, and all hardware/crash durability scenarios are not covered. For production, use a transactional database, appropriate access controls, and a dedicated audit and recovery policy. PHP integers limit the representable range; arithmetic and revision overflow are rejected.

___
# How This Code Was Built

All sections above were drafted with JetBrains Junie and thoroughly reviewed by me. I chose Junie for this exercise as it integrates directly into my PhpStorm workflow without requiring additional subscriptions.

*P.S. I have a corporate Claude subscription, so I intentionally avoided using work resources for a personal test assignment.*

## How this code was created
1. I studied the task specifications and edge cases in detail.
2. I selected PHP 8.3, a snapshot-based JSON persistence strategy with optimistic locking, and a TDD workflow.
3. Initialized the repository structure and Git locally:
```sh
mkdir alcor-payroll-poc && cd alcor-payroll-poc
mkdir -p src/Domain/Exception src/Domain/ValueObject tests/Unit bin
git init
```
4. I set up Dockerfile, docker-compose.yml, composer.json, phpunit.xml, .gitignore and Makefile for this project
5. I copied the task description to this directory and wrote a prompt for Junie:
```sh
є тестове завдання test.pdf
ти зараз знаходишся в папці з докерналаштуваннями, яку я створила під це тестове завдання
я хочу виконати це завдання по методології tdd. Тобто спочатку написати юніт тести, а далі самі функції.
Запропонуй покрокову реалізацію цього тесту, як сеньйор розробник php. З урахуванням всіх вимог, а також коментарів, що є в темплейті листа, який описано на останній сторінці файлу
```
6. I reviewed the code in detail
7. After testing I created branches following Git Flow for different steps and committed the changes

# Thank you for your time reviewing this task. Have a great day!