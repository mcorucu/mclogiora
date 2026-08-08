# Translation Status Lifecycle

Phase 04 defines status constants only. It does not implement workflow transitions.

## Statuses

- `original`: the source item in a relation group.
- `missing`: a target language has no item yet.
- `draft`: a target item exists but is not complete.
- `translated`: a target item is complete enough to be treated as translated.
- `needs_review`: a target item requires human review.
- `needs_update`: the source changed after the target was last aligned.
- `machine_suggested`: a suggestion exists but has not been accepted by a human.
- `disabled`: the item is excluded from active translation workflows.

## Future Transition Ideas

Future phases may support transitions such as:

- `missing` to `draft`
- `draft` to `needs_review`
- `needs_review` to `translated`
- `translated` to `needs_update`
- `machine_suggested` to `draft`
- any active target state to `disabled`

These transitions are intentionally not implemented in Phase 04.
