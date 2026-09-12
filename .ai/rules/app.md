---
paths:
  - 'app/**'
---

# Online LMS access

The active product is an online LMS. Course access uses account roles, course ownership/co-instructors and online enrollments. No campus role context or degree registration is required. Assessment mutations use lms_write_locks row 1 first within their transactions; use that ordering for enrollment and permission mutations too.
