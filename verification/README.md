# Verification inputs and evidence

This directory owns executable verification contracts independently of planning tools.
`root/contracts/` contains the versioned gate contracts; `provenance.json` records their extraction from the migrated plan documents. That extraction was byte-checked against the recorded SHA-256. `decisions/` carries the relevant decision in its migrated JSON format.

`findings/` contains both historical migration baselines and reports produced by owner commands. Historical phase reports describe their original execution, not the current checkout. `artifacts/` contains corresponding machine-readable receipts; regenerate them with the commands in DEVELOPMENT.md before relying on a current verdict. Copied receipts are never relabelled as fresh execution.

The original plans and their JSON migration remain independent historical/project records. Gate changes belong here and in their owning tools, not in those plans.
