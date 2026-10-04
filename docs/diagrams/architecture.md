# A. System architecture

Request path through the application. Blade renders every page; there is no client-side router,
no API consumed by a frontend, and no build step.

```mermaid
flowchart TD
  B["Browser<br/>Blade pages, progressive-enhancement JS"]
  M["Middleware<br/>auth · auth.session · EnsureAccountIsActive · SecurityHeaders (CSP)"]
  R["routes/web.php<br/>single route file"]
  C["Controllers<br/>11, thin"]
  FR["Form Requests<br/>authorize + rules"]
  P["CoursePolicy<br/>manage · view · participate · enroll · studySource"]
  A["Actions<br/>AssessmentWorkflow · QuizWorkflow · VideoLectureWorkflow<br/>ContentLifecycle · AssignmentMediaLibrary · WriteLock"]
  S["Services<br/>AiSettings · NotesProvider · QuizQuestionProvider · SourceText<br/>DocumentText · VideoProbe · PrivateMediaStream · RichText<br/>DisplayTime · DependencyAnalyzer"]
  E["Eloquent models + query-builder tables"]
  DB[("MySQL<br/>application data")]
  FS[("Private disk<br/>storage/app/private")]
  Q["Database queue<br/>GenerateStudyNotes"]
  X["AI provider<br/>OpenRouter / OpenAI / mock"]

  B --> M --> R --> C
  C --> FR
  C --> P
  C --> A
  A --> P
  A --> S
  A --> E
  C --> S
  S --> FS
  E --> DB
  A --> DB
  C --> Q
  Q --> S
  S -. "HTTPS, server-side credential" .-> X

  subgraph Serialization
    A -. "lms_write_locks row 1, first statement in the transaction" .-> DB
  end
```

Notes that are easy to miss:

- Every multi-step mutation takes the shared write lock before reading anything, then re-checks
  authorization against freshly loaded state inside the same transaction.
- Private files never have a public URL. Bytes leave only through an authorized route, and video
  goes through `PrivateMediaStream` so Range requests work without buffering the file.
- The AI provider is reached only from the server, with the credential encrypted at rest.
