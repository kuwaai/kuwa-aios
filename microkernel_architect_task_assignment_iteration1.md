**Subject: Iteration 1 Task - Microkernel Foundational Design Outline**

**To: Microkernel Architect**
**From: Skyscope Sentinel Intelligence - AI OS (Orchestrator)**
**Stage: 1 - Requirements Analysis & Architecture Design**
**Iteration: 1**

**Directive:**

You are tasked with producing the foundational design outline for the Skyscope OS Microkernel. This document is critical for establishing the core architectural principles of the operating system.

**Key Requirements for the Outline:**

1.  **Core Principles:**
    *   Clearly articulate the microkernel's commitment to **minimality**, **formal verifiability** (with the ultimate goal of achieving such verification), and a robust **capability-based security model**.
    *   Explicitly reference **seL4's core principles and mechanisms** as the primary inspiration and baseline.
    *   Discuss the importance of a minimal Trusted Computing Base (TCB).

2.  **Inter-Process Communication (IPC):**
    *   Propose **high-performance, secure IPC mechanisms** that will form the primary means of interaction between all isolated user-space components (including drivers, filesystems, and applications).
    *   Detail the preferred IPC model (e.g., synchronous PPCs via endpoints, drawing from seL4).

3.  **Preliminary Core Kernel Services Discussion:**
    *   Provide an initial discussion on the essential kernel services that will be provided. This should cover:
        *   Inter-Process Communication (IPC)
        *   Memory Management (aligned with seL4's untyped memory and capabilities)
        *   Scheduling (mentioning support for real-time and fair-share, and potentially seL4 Mixed Criticality Systems extensions)
        *   Thread Management
        *   The Capability System (as the foundation for all access control).
    *   Reiterate that all other OS services (e.g., device drivers, filesystems) are to be implemented as isolated user-space servers.

4.  **Deviations and Justifications:**
    *   While adhering closely to seL4's philosophy, briefly address the process for considering any potential deviations (e.g., richer primitives inspired by Zircon).
    *   Emphasize that any such considerations must be rigorously justified, focusing on how isolation, verifiability, and minimality are preserved.

**Output Format:**

*   A structured document (e.g., Markdown) suitable for inclusion in the project's shared secure repository.
*   This document will serve as a key input for the Project Coordinator's synthesis of the unified OS architecture.

**Deliverable:**
*   Commit the 'Microkernel Foundational Design Outline V0.1' to the designated shared repository.

**Deadline:** [To be set by Project Coordinator]

Please confirm receipt of this task and proceed with the design. Your output will be integrated with specifications from the Filesystem Engineer and AI Pipeline Specialist.
