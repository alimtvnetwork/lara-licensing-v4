# Shard migrations

Per-reseller shard tables land here in Plan 06 steps 14-18. `lara:shard:provision`
runs `migrate --database=shard --path=database/migrations/shard` against a
freshly created shard database; an empty folder is valid and produces a
noop migration frontier (the reseller row still moves to `Active`).
