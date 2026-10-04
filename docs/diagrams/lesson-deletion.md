# C. Lesson deletion

The implemented path, not an idealised one.

```mermaid
sequenceDiagram
  actor Staff
  participant UI as Course page / Trash
  participant C as CourseController
  participant G as CoursePolicy
  participant L as ContentLifecycle
  participant D as DependencyAnalyzer
  participant DB as MySQL

  Staff->>UI: Delete permanently
  UI->>C: DELETE /lessons/{lesson} (version, confirm)
  C->>G: authorize('manage', course)
  G-->>C: allowed
  C->>L: deleteLesson(actor, lesson, input)
  L->>DB: BEGIN
  L->>DB: lock lms_write_locks row 1
  L->>DB: SELECT lesson FOR UPDATE
  L->>G: re-authorize against fresh state
  L->>L: reject stale version (409)
  L->>D: for('lessons', id)
  D->>DB: read foreign keys referencing lessons
  D->>DB: count rows per referencing table

  alt Nothing refers to the lesson
    D-->>L: blocked = false
    L->>DB: DELETE lesson
    L->>DB: COMMIT
    L-->>C: deleted = true
    C-->>Staff: "Lesson permanently deleted."
  else Completions, notes or materials exist
    D-->>L: blocked = true, counts per table
    L->>DB: UPDATE lesson SET status = archived
    L->>DB: COMMIT
    L-->>C: deleted = false, explanation
    C-->>Staff: "Unavailable: N learner progress records<br/>depend on this. Moved to Trash instead."
  end
```

The database constraints remain the final guard: if the analyser were ever wrong, `RESTRICT`
refuses the delete rather than orphaning a record. Constraints are never disabled.
