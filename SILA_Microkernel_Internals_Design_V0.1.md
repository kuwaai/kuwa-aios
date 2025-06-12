# SILA Microkernel Internals Design V0.1

## 1. Introduction
This document elaborates on the `Microkernel SILA Design Specification V0.1` from Stage 1, providing a more granular view of the Skyscope OS microkernel's internal data structures and operational logic as implemented using **SILA (Sentient Intermediate Language for Agents)**. The focus is on how SILA's semantic graph representations and unique features are used to construct these mechanisms.

## 2. Detailed SILA Data Structures

### 2.1. Thread Control Block (TCB)
A TCB is represented as a SILA Record structure, conceptually:
`SILA_TCB_Record {
  tcb_id: SILA_UniqueID,
  state: SILA_ThreadState_Enum { Running, Ready, Blocked, Suspended },
  priority: SILA_SchedulingPriority_Int,
  registers: SILA_PQC_Encrypted<MLKEM_1024, SILA_RegisterSet_Struct>, // Register set PQC encrypted when not on CPU
  cspace_root_cap: SILA_CapToken, // Capability to its root CNode
  vspace_root_cap: SILA_CapToken, // Capability to its root virtual address space descriptor (e.g., page table root)
  ipc_buffer_addr_cap: SILA_CapToken, // Capability to its IPC buffer frame
  fault_ep_cap: SILA_CapToken, // Capability to its fault handler endpoint
  sched_params_cap: SILA_CapToken // Capability to its scheduling parameters object (e.g., MCS context)
}`

### 2.2. Capability Spaces (CSpaces and CNodes)
*   **SILA_CNode_Record:** Represents a CNode.
    `SILA_CNode_Record {
      slots: SILA_Array<SILA_CapToken_Slot_Struct> // Fixed-size array based on CNode depth
    }`
*   **SILA_CapToken_Slot_Struct:**
    `SILA_CapToken_Slot_Struct {
      cap_token: SILA_Optional<SILA_CapToken>, // The actual capability, if any
      guard: SILA_Guard_Type, // Guard value for derived capabilities
      radix_bits: SILA_Int // For CNode tree structure
    }`
    (Note: Actual CSpace representation in SILA might be a more complex graph to optimize lookup, but conceptually it's an array/tree of slots.)

### 2.3. Endpoints and Notification Objects
*   **SILA_Endpoint_Record:**
    `SILA_Endpoint_Record {
      ep_id: SILA_UniqueID,
      state: SILA_EndpointState_Enum { Idle, Sending, Receiving },
      queue_head_tcb_cap: SILA_Optional<SILA_CapToken>, // Head of TCB queue waiting on this EP
      badge: SILA_Badge_Type // For identifying senders
    }`
*   **SILA_Notification_Record:** (Similar structure, for asynchronous signalling)

### 2.4. Address Space Descriptors (Conceptual Page Table Structure)
*   Represented as a hierarchical SILA graph structure (e.g., a tree of SILA_PageTableNode_Records).
*   `SILA_PageTableNode_Record {
      level: SILA_Int,
      entries: SILA_Array<SILA_PageTableEntry_Struct>
    }`
*   `SILA_PageTableEntry_Struct {
      is_valid: SILA_Bool,
      target_frame_cap: SILA_Optional<SILA_CapToken>, // Capability to the physical frame
      permissions: SILA_MemoryAccessRights_Enum, // Read, Write, Execute
      attributes: SILA_MemoryAttributes_Struct, // Cacheability, etc.
      pqc_protection_flags: SILA_PQC_Flags_Enum // If entry itself needs signing/encryption
    }`
    (Critical entries or entire table structures can be PQC-signed via SILA's type system if needed.)

## 3. SILA Semantic Graph Logic for Critical Operations (Conceptual)

### 3.1. Context Switching
1.  **Trigger:** SILA event (e.g., timer interrupt, syscall yielding CPU).
2.  **Save Current TCB State (SILA Graph Nodes):**
    *   Read current CPU registers into a temporary `SILA_RegisterSet_Struct`.
    *   Invoke `SILA_PQC_Encrypt` to encrypt this struct using a TCB-specific key, result stored in `current_tcb.registers`.
    *   Update `current_tcb.state` to Ready or Blocked.
3.  **Select Next TCB (Scheduler Logic - SILA Graph):**
    *   Invoke SILA scheduler module (which manipulates SILA TCB queues based on priority/policy).
    *   Obtain `next_tcb_cap: SILA_CapToken`.
4.  **Restore Next TCB State (SILA Graph Nodes):**
    *   Invoke `SILA_PQC_Decrypt` on `next_tcb.registers` to get `SILA_RegisterSet_Struct`.
    *   Write these registers to CPU.
    *   Update `next_tcb.state` to Running.
    *   Switch active `vspace_root_cap` and `cspace_root_cap` to those of `next_tcb`.

### 3.2. IPC Message Transfer (`Call` Operation)
1.  **Initiation:** Calling thread's SILA graph invokes `SILA_IPC_Call_Operation(target_ep_cap, message_sila_struct, reply_buffer_cap)`.
2.  **Capability Check:** SILA runtime (kernel) verifies `target_ep_cap` validity and rights.
3.  **Message Buffer Access:**
    *   Kernel accesses caller's IPC buffer (via `caller_tcb.ipc_buffer_addr_cap`) to read `message_sila_struct`. SILA ensures type safety.
    *   If `message_sila_struct` is `SILA_PQC_Aware_Buffer`, relevant crypto operations are implicit or explicit.
4.  **Target Endpoint Logic:**
    *   Kernel locates `SILA_Endpoint_Record` via `target_ep_cap`.
    *   If receiver is waiting: Transfer message to receiver's IPC buffer, set receiver state to Ready. Create a temporary `SILA_Reply_CapabilityToken` (linked to caller) and grant to receiver. Set caller state to Blocked (awaiting reply).
    *   If no receiver: Enqueue caller on endpoint's TCB queue. Set caller state to Blocked.
5.  **Reply Path:** Similar logic for `SILA_IPC_Reply_Operation` using the `SILA_Reply_CapabilityToken`.

### 3.3. Capability Derivation (`Cap_Mint`)
1.  **Invocation:** SILA graph operation `SILA_Cap_Mint_Operation(src_cnode_cap, src_slot_idx, dest_cnode_cap, dest_slot_idx, new_rights_sila_struct, new_guard_sila_struct)`.
2.  **Source & Dest CNode Access:** Kernel uses `src_cnode_cap` and `dest_cnode_cap` to access respective `SILA_CNode_Record` structures.
3.  **Rights Check:** Verify `new_rights_sila_struct` are a subset of rights in source capability.
4.  **New Capability Token Generation:** SILA runtime creates a new `SILA_CapToken` with the derived rights and new guard.
5.  **Update Destination CNode:** Place new token in `dest_cnode.slots[dest_slot_idx]`.

## 4. Fault Handling Mechanisms in SILA
1.  **Hardware Trap:** CPU traps on an exception (e.g., page fault). Minimal hardware/assembly stub transfers control to a predefined SILA kernel entry point.
2.  **SILA Kernel Fault Dispatcher (SILA State Machine/Event Handler):**
    *   Identifies fault type and retrieves faulting instruction pointer, faulting address, etc.
    *   Accesses current `SILA_TCB_Record` to get its `fault_ep_cap: SILA_CapToken`.
3.  **Send SILA Fault Message:**
    *   Constructs a `SILA_Fault_Message_Struct` (e.g., `{ fault_type: PageFault, fault_address: SILA_Address, access_mode: Read }`).
    *   Invokes `SILA_IPC_Send_Operation(fault_ep_cap, fault_message_sila_struct)`.
4.  **User-Space Fault Handler (SILA):** The thread registered with `fault_ep_cap` receives the SILA message and handles the fault (e.g., requests page from SKYAIFS).

## 5. Bootstrapping a SILA-based Microkernel
1.  **Minimal Bootloader (Non-SILA or ROM-based SILA stub):**
    *   Performs basic hardware initialization (CPU, memory controller).
    *   Loads the core compiled SILA microkernel binary (which includes a minimal SILA runtime) into memory.
    *   (Optional) Verifies PQC signature of the SILA microkernel binary.
2.  **Initial SILA Runtime Setup:**
    *   The minimal SILA runtime initializes itself.
3.  **Execution of Initial SILA Graph:**
    *   Control transfers to the entry point of the compiled SILA microkernel.
    *   This initial SILA graph programmatically:
        *   Creates the first `SILA_TCB_Record` (for the initial kernel/boot task).
        *   Creates its root `SILA_CNode_Record` and `SILA_AddressSpace_Descriptor_Record`.
        *   Creates initial `SILA_UntypedMemory_CapabilityToken`s representing available physical memory.
        *   Initializes core kernel services (IPC, scheduling) by creating their SILA object representations.
4.  **Verification of Core SILA Modules (Optional):**
    *   The initial SILA graph may invoke the SILA Verifier logic (if embedded or accessible) to check integrity/correctness of subsequently loaded core SILA kernel modules against their PQC-signed metadata.
5.  **Start First User-Level SILA Process(es):** E.g., SKYAIFS, AI Pipeline Manager.

## 6. Refined SILA Interfaces for SKYAIFS & Deep Compression

### 6.1. SKYAIFS Privileged Operations
*   `SILA_Op_Kernel_GrantRawDeviceAccess(requested_device_id: SILA_String_Record) -> SILA_Result_Record<SILA_CapToken, SILA_ErrorCode_Enum>`
    *   *Purpose:* SKYAIFS calls this to get a capability token for a raw storage device.
*   `SILA_Op_Kernel_MapDeviceMemory(device_cap: SILA_CapToken, offset: SILA_PhysicalAddress, size: SILA_Size, map_rights: SILA_MemoryAccessRights_Enum) -> SILA_Result_Record<SILA_CapToken, SILA_ErrorCode_Enum>`
    *   *Purpose:* SKYAIFS maps MMIO regions of a device it has capability to.
*   `SILA_Op_Kernel_RegisterIRQHandler(device_cap: SILA_CapToken, irq_id: SILA_Int, handler_ep_cap: SILA_CapToken) -> SILA_Result_Record<SILA_Void, SILA_ErrorCode_Enum>`
    *   *Purpose:* SKYAIFS registers a SILA endpoint to receive hardware interrupts from a device.

### 6.2. Deep Compression Service Support
*   `SILA_Op_Kernel_AllocateSecureMemoryForDecompression(size_hint: SILA_Size, numa_node_preference: SILA_Int) -> SILA_Result_Record<SILA_CapToken, SILA_ErrorCode_Enum>`
    *   *Purpose:* Deep Compression service calls this to get a capability to a secure memory region for decompressing SILA modules.
*   `SILA_Op_Kernel_ExecuteSILAFromVerifiedMemory(module_code_cap: SILA_CapToken, entry_params_sila_struct: SILA_Any_Record, owner_tcb_cap: SILA_CapToken) -> SILA_Result_Record<SILA_ProcessID_Type, SILA_ErrorCode_Enum>`
    *   *Purpose:* Used by module manager (after deep compression) to execute a SILA module from a memory capability known to be verified. (This is a high-level conceptual call, actual module loading is more complex).

This detailed design provides a conceptual blueprint for how SILA's unique features can be used to construct a secure, verifiable, and AI-agent-friendly microkernel.The first document, `SILA_Microkernel_Internals_Design_V0.1.md`, has been created.

Now, I'll proceed to the second document:
**2. Filesystem Engineer's Output (Detailed SKYAIFS SILA Logic):**
   *   Create: `SKYAIFS_Detailed_SILA_Logic_V0.1.md`
