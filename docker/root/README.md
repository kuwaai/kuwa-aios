## Skyscope Filesystem Hierarchy

The Skyscope filesystem houses user-uploaded data during runtime. Here's a breakdown of the directory structure:

**Version 0.3.3 (Introduced in Skyscope v0.3.3)**

- **`/bin`**: This directory contains executable tools accessible to the pipe executor.
- **`/database`**: This directory stores the Retrieval Augmented Generation (RAG) database, which the DBQA executor can access and query.
- **`/custom`**: This directory contains user-customized web components for the multi-chat web UI.