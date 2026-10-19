# Administrative reports package

This branch contains administrative/report changes relative to baseline `c62dc36`.
The baseline contains earlier local backend commits and is ahead of GitHub `main`.
Review this package against that baseline before merging the entire history into main.

Included: Excel reports; six-digit public client IDs; a nullable tariff-type snapshot;
station addresses and document-only removal of power ratings; backoffice report links
and refund labels. Existing internal IDs and station names returned to mobile stay unchanged.

New schema columns remain nullable for older running processes. Historical tariff names
fall back to the current account type when no snapshot exists.

Excluded: OCPP stop/resume/reset changes, wallet charging limits, billing calculations,
mobile releases, forced-update settings, deployment scripts, generated artifacts and credentials.

Pushing this branch does not deploy it. No server action or maintenance is required.
A future deployment must apply the migrations before code selecting the new columns is served.
