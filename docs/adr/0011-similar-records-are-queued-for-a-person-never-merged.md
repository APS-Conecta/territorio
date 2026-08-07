# Similar records are queued for a person, never merged automatically

Import matches on the exact external id and on nothing else. Two records that merely look like the
same place — identical coordinates under different names, one institution spelled differently by
two sources — become a candidate pair in a review queue, and both stay visible and usable until
somebody decides. The app never merges on similarity, at any threshold, however confident.

This is a choice about how the two mistakes fail, not about how hard the matching is.

A pair left in a queue is visible and harmless. Somebody opens it, reads two names, and either
merges them or says they are different places; the cost is a minute and the Registry was correct
the whole time.

A wrong merge fails **silently**. One of two real places disappears from the map, the list and
every count, and nothing anywhere says a decision was made. Nobody discovers it by looking at the
screen — they discover it by phoning the wrong place. In this app that means a health team going
to an address where the family is not, which is exactly the failure the Registry exists to prevent.

Five sources produced 147 candidate pairs in the real La Florida data. A threshold tuned to merge
most of them automatically would be right most of the time, and the times it was wrong would not be
findable.

## Consequences

- Detection may be generous, because a false positive costs a glance. It may not be so generous
  that the queue stops being read: "Sede Junta de Vecinos" is thirty different buildings in one
  comuna, so a similar name only counts within a short distance of another.
- The queue must show how many pairs are outstanding, or it is a page nobody opens.
- A decision is stored, never recomputed. Dismissing a pair as "not duplicates" is a judgement
  about two real places, and re-offering it after the next import would teach people to close the
  queue and leave it closed.
- Merging retires the loser and records a pointer to the survivor. Under ADR-0005 that makes it
  reversible for free — restoring the record is the undo — and it keeps the mapping, which is what
  `fonasa_eliminados.csv` was doing by hand.
- Undoing a merge reopens its pair. The two records are both on the map again and still look like
  one place, so the question is open again.
- The survivor is always named by the person. Which of two records is the better one is a judgement
  about the place; the newer row is not the truer one.
