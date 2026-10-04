# B. Course and learning flow

Derived from the actual schema; every arrow is a real foreign key.

```mermaid
flowchart TD
  CO["Course<br/>draft · published · archived"]
  LE["Lesson<br/>active · archived"]
  MA["Material<br/>active · archived"]
  VL["VideoLecture<br/>draft · published · archived"]
  AS["Assignment"]
  AM["AssignmentMedium<br/>image · video"]
  QZ["Quiz<br/>draft · published<br/>window optional"]
  AN["Announcement<br/>draft · scheduled · published"]

  EN["Enrollment"]
  LC["LessonCompletion"]
  VP["VideoLectureProgress"]
  SU["Submission"]
  SR["SubmissionRevision"]
  QA["QuizAttempt"]
  RP["ResultPublication"]
  GC["AssessmentGradeChange"]
  QC["QuizAssessmentChange"]
  SN["StudyNote"]
  AR["AnnouncementRead"]

  CO --> LE
  CO --> MA
  CO --> VL
  CO --> AS
  CO --> QZ
  CO --> AN
  AS --> AM
  LE -.->|"optional link"| MA
  LE -.->|"optional link"| VL

  CO --> EN
  EN -->|"learner"| LC
  LE --> LC
  VL --> VP
  AS --> SU
  SU --> SR
  SU --> RP
  SU --> GC
  QZ --> QA
  QA --> QC
  AN --> AR

  LE --> SN
  MA --> SN
  VL --> SN

  classDef history fill:#eef3ec,stroke:#7f9b7c;
  class LC,VP,SU,SR,QA,RP,GC,QC,SN,AR history;
```

Shaded nodes are learner history. Each one is a `RESTRICT` reference, which is what makes the
parent undeletable while it exists.
