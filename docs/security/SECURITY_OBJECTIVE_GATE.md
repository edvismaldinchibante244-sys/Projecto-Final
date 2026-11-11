# Nivora Security Objective Gate

The production security objective is:

- 0 critical failures in security/backend/ops preflight.
- Readiness score at least 85/100.
- WAF active and reviewed within cadence.
- Backup restore/DR drill proven within cadence.
- Monitoring and alerting active.
- No pending CRITICAL or HIGH pentest findings.

Run:

```powershell
php tools/security_objective_gate.php
php tools/security_objective_gate.php --json
```

The latest machine-readable result is written to:

```text
app/storage/logs/security_objective_gate.json
```

## Recording Reviewed Evidence

After a real WAF review, pentest/retest review, and incident-response tabletop, record the attestation with:

```powershell
php tools/record_security_attestation.php --confirm=EVIDENCE_REVIEWED --waf --pentest --ir-tabletop --reviewer="Nome do responsavel" --reference="Ticket, relatorio ou URL da evidencia"
```

This updates:

- `docs/security/security_governance.json`
- `docs/security/WAF_EVIDENCE.md`
- `docs/security/PENTEST_FINDINGS.json`
- `docs/security/IR_TABLETOP_EVIDENCE.md`

## Evidence Files

- `docs/security/security_governance.json`
- `docs/security/WAF_EVIDENCE.md`
- `docs/security/PENTEST_FINDINGS.json`
- `docs/security/DR_DRILL_EVIDENCE.md`
- `app/storage/logs/security_ops_status.json`
- `app/storage/logs/dr_last_test.json`

## Important

Templates are not proof. The gate must not be forced to green without real WAF review, DR validation, monitoring evidence, and pentest/retest evidence.
