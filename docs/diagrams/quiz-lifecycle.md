# D. Quiz lifecycle

```mermaid
sequenceDiagram
  actor Instructor
  actor Learner
  participant W as QuizWorkflow
  participant T as DisplayTime
  participant AI as QuizQuestionProvider
  participant DB as MySQL
  participant S as quizzes:finalize

  Instructor->>W: create (title, optional window, duration)
  W->>T: normalize window PKT -> UTC, before validation
  W->>W: assertWindow (coherent, or absent)
  W->>DB: INSERT status = draft
  opt AI assistance
    Instructor->>W: generateQuestions (source, count, level)
    W->>AI: generate from course source
    AI-->>W: parsed, de-duplicated draft questions
    W->>DB: append as DRAFT questions
  end
  Instructor->>W: author (add / remove questions)
  Instructor->>W: publish
  W->>DB: status = published, question set frozen

  Learner->>W: start
  W->>W: isOpen? (a missing bound is not a bound)
  W->>DB: INSERT attempt, deadline_at = min(now + duration, closes_at)
  Learner->>W: answer (save / submit)
  alt Submitted before the deadline
    W->>DB: score objective answers, submitted_at = now
  else Deadline passed
    S->>DB: finalize saved answers only
  end

  Instructor->>W: review (manual marks for short answers)
  Instructor->>W: publishResult
  alt Quiz has a closing time
    W->>W: refuse until the quiz closes
  else No closing time
    W->>W: the learner's finalized attempt is the gate
  end
  W->>DB: published_result, result_published_at
```

Editing after publication changes the title, instructions and window only. Attempts keep the
`deadline_at` recorded when they began, so moving the window never alters time already promised.
