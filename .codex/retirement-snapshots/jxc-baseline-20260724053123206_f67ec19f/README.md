# JXC working-tree baseline

This is a detection baseline only, not a source recovery package.
The 13 listed app/api/jxc paths are user-confirmed existing changes outside the supplier retirement scope.
Compare current raw bytes with jxc-files-sha256.tsv before treating a later change as part of another delivery.
SupplyOrderController.php is protected by raw SHA-256 because it has no normalized text patch.
On any mismatch, stop and investigate; do not overwrite JXC source files from this directory.
