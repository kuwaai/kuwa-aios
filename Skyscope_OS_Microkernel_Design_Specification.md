# Skyscope OS Microkernel Design Specification

## 1. Core Philosophy and Alignment with seL4

### 1.1. Commitment to Minimality, Formal Verifiability, and Capability-Based Security
Skyscope OS firmly adopts the core principles of minimality, formal verifiability, and capability-based security, inspired by the seL4 microkernel. Our commitment to these principles is paramount. A minimal trusted computing base (TCB) is a cornerstone of our design, reducing the attack surface and complexity, thereby enhancing security and reliability. We aim to achieve formal mathematical proof of correctness for the core microkernel, ensuring that its implementation precisely matches its specification.

### 1.2. Inter-Process Communication (IPC) Mechanisms
The primary Inter-Process Communication (IPC) mechanism in Skyscope OS will be synchronous Protected Procedure Calls (PPCs) utilizing endpoints, mirroring seL4's approach. This choice offers significant benefits in terms of performance, as it minimizes overhead; security, by ensuring all communication is kernel-mediated and subject to capability checks; and verifiability, as the IPC mechanism itself can be part of the formal verification process. All user-space services, including device drivers, filesystems, and applications, will communicate exclusively through these kernel-mediated IPC channels, ensuring strict isolation and controlled interaction.

### 1.3. Consideration of Zircon-Inspired Extensions
While acknowledging the richer IPC mechanisms (e.g., channels, fifos, signals) and object model (e.g., jobs, processes, events) present in systems like Zircon, Skyscope OS will approach any such extensions with extreme caution. Any proposed extension beyond the seL4-like minimal IPC and object model would be considered only if it demonstrably enhances essential functionality. Crucially, such extensions must not compromise the core principles of minimality, security, or the feasibility of achieving formal verification for the core kernel. A strict evaluation process will be established for any proposed deviation from seL4's minimality. This process will require rigorous justification demonstrating how the proposed extension preserves strong isolation guarantees and does not unduly complicate or render impossible the formal verification of the microkernel.

## 2. Core Kernel Services and System Call API

### 2.1. Overview
The system call API of the Skyscope OS microkernel will be intentionally minimal. It will expose only the essential primitives required for managing fundamental hardware resources and kernel objects. Higher-level operating system services, such as filesystems, device drivers, and network stacks, are not implemented within the kernel. Instead, they operate as isolated user-space server processes, interacting with each other and applications via the kernel's IPC mechanisms.

### 2.2. System Call Categories and Definitions (High-Level)

#### 2.2.1. Inter-Process Communication (IPC)
*   `Send(endpoint_cap, message_data)`: Sends a message to the kernel object (e.g., endpoint) referenced by `endpoint_cap`. The message data is transferred. This is a non-blocking call.
*   `Receive(endpoint_cap, &message_data_buffer)`: Blocks until a message arrives at the kernel object (e.g., endpoint) referenced by `endpoint_cap`. The received message is written to `message_data_buffer`.
*   `Call(endpoint_cap, message_data, &reply_data_buffer)`: A synchronous operation combining `Send` and `Receive`. It sends a message to `endpoint_cap` and then blocks awaiting a reply. The reply is written to `reply_data_buffer`. This is the primary mechanism for RPC-like interactions.
*   `Reply(reply_cap, reply_data)`: Sends a reply message to a thread that previously invoked `Call` and is now waiting. `reply_cap` is a special capability implicitly provided to the callee to allow it to reply.
*   *(Consideration for non-blocking variants of `Receive` or `Call` will be subject to rigorous review to ensure they do not introduce undue complexity or hinder verifiability. If implemented, they would likely involve polling or notification mechanisms.)*

#### 2.2.2. Memory Management
Memory management is based on seL4's model, where physical memory is initially treated as 'untyped' objects. Capabilities to these untyped regions are used to derive other kernel objects.
*   `Untyped_Retype(untyped_cap, object_type, size_bits, &new_object_cap)`: Takes a capability to an untyped memory region (`untyped_cap`) and retypes a portion of it into a new kernel object of `object_type` (e.g., Endpoint, TCB, AddressSpace, CNode, or more UntypedMemory of smaller size). The `size_bits` parameter specifies the size of the new object. A capability to the new object is returned in `new_object_cap`.
*   `AddressSpace_Map(as_cap, vaddr, frame_cap, rights, attributes)`: Maps a physical memory frame, referenced by `frame_cap`, into the virtual address space object referenced by `as_cap` at the virtual address `vaddr`. `rights` (e.g., Read, Write, Execute) and `attributes` (e.g., cacheability, memory type) control the mapping.
*   `AddressSpace_Unmap(as_cap, vaddr)`: Unmaps the memory frame currently mapped at `vaddr` within the address space `as_cap`.
*   `Frame_Create(paddr, size, &frame_cap)`: This system call might be considered for bootstrapping scenarios where initial physical frame capabilities need to be created directly from known physical addresses, especially if not all physical memory is initially represented as untyped objects. However, the preference is to derive frames from untyped memory.

#### 2.2.3. Scheduling and Thread Management
*   `TCB_Create(as_cap, fault_handler_ep_cap, &tcb_cap)`: Creates a new Thread Control Block (TCB) object. The TCB will be associated with the address space `as_cap` and will use `fault_handler_ep_cap` as the endpoint to which fault messages (e.g., page faults, exceptions) are sent. A capability to the new TCB is returned in `tcb_cap`.
*   `TCB_Configure(tcb_cap, vspace_root, cspace_root, ipc_buffer_addr, priority, etc.)`: Configures various properties of the thread associated with `tcb_cap`. This includes setting its virtual address space root (`vspace_root`), capability space root (`cspace_root`), the virtual address of its IPC buffer (`ipc_buffer_addr`), its scheduling priority, and potentially other parameters.
*   `TCB_WriteRegisters(tcb_cap, &regs_struct)`: Sets the general-purpose registers, instruction pointer, stack pointer, and flags/status registers for the thread associated with `tcb_cap`. This is typically used to initialize a thread for execution or to modify its context.
*   `TCB_ReadRegisters(tcb_cap, &regs_struct)`: Reads the current register state of the thread associated with `tcb_cap`.
*   `TCB_Resume(tcb_cap)`: Starts or resumes the execution of the thread associated with `tcb_cap`.
*   `TCB_Suspend(tcb_cap)`: Suspends the execution of the thread associated with `tcb_cap`.
*   `SchedControl_Configure(sc_cap, tcb_cap, budget, period, data)`: (Inspired by seL4 MCS) Configures scheduling parameters for `tcb_cap` using a scheduling context capability `sc_cap`. This allows setting parameters like execution `budget` within a `period` (for real-time guarantees) or other policy-specific `data` for fair-share scheduling.
*   `Yield()`: Allows the currently executing thread to voluntarily cede the CPU to another ready thread.

#### 2.2.4. Capability System
Capabilities are the core of the security model. They are not typically created by direct system calls but are derived from existing capabilities or managed as side effects of operations on kernel objects.
*   `Cap_Copy(dest_cnode_cap, dest_index, src_cnode_cap, src_index, rights)`: Copies a capability from a source CSlot (`src_cnode_cap` at `src_index`) to a destination CSlot (`dest_cnode_cap` at `dest_index`). The copied capability can have `rights` that are a subset of the source capability's rights.
*   `Cap_Mint(dest_cnode_cap, dest_index, src_cnode_cap, src_index, rights, guard, guard_value)`: Creates a new, derived capability in the destination CSlot. This capability is derived from the source capability but can have modified `rights` and a `guard` (a value that must be matched for the capability to be used for certain operations, effectively parameterizing the capability).
*   `Cap_Delete(cnode_cap, index)`: Deletes the capability stored in the CSlot at `index` within the CNode referenced by `cnode_cap`.
*   `Cap_Revoke(cnode_cap, index)`: Revokes all capabilities that were derived (copied or minted) from the capability at the specified CSlot. This is a powerful mechanism for invalidating access rights.
*   `CNode_Create(parent_cnode_cap, index, depth, &new_cnode_cap)`: Creates a new CNode (a table for storing capabilities) within an existing CNode (the `parent_cnode_cap` at `index`). `depth` specifies the size of the new CNode. A capability to the new CNode is returned. This is how CSpaces are constructed.

### 2.3. Principle of Least Privilege in API Design
The Principle of Least Privilege is intrinsically woven into the Skyscope OS system call API through the capability system. Every system call that operates on a kernel object (e.g., TCBs, Endpoints, Address Spaces, Frames, CNodes) requires the calling thread to possess a valid capability to that object. Furthermore, the capability must grant the specific rights necessary for the requested operation. For instance, to map a memory frame into an address space, the caller needs a capability to the address space object with mapping rights and a capability to the frame object with read/write rights. This ensures that components can only perform actions for which they have explicit authorization, minimizing the potential impact of a compromised component.

## 3. Runtime Immutability and Memory Protection

### 3.1. Hardware-Enforced Memory Protection
Runtime immutability of the kernel itself is a critical security feature. Upon system initialization, the microkernel will configure the hardware Memory Management Unit (MMU) or equivalent Memory Protection Unit (MPU). Kernel code sections (.text) and critical read-only data structures (e.g., system configuration, initial capability tables if static) will be marked as non-writable in their respective page table entries (PTEs) or memory region descriptors. Any subsequent attempt by any component, including the kernel itself (outside of well-defined, highly privileged boot or update procedures), to write to these protected memory regions will trigger a hardware fault (e.g., a page fault or protection fault). This fault will be trapped and handled by the kernel, typically by terminating the offending component or, in the case of an internal kernel error, potentially halting the system to prevent further corruption and ensure system integrity.

### 3.2. Capability System Control for Kernel State
Access to and modification of kernel objects and critical kernel state are exclusively mediated by the capability system. Operations that alter the state of the kernel, such_as changing scheduling parameters of a thread, modifying a thread's TCB (e.g., its instruction pointer or priority), altering page table mappings, or manipulating capabilities themselves, all require the invoking thread to possess a specific, authorized capability granting the right to perform that operation on the target object.
Following system initialization and boot, these powerful capabilities (e.g., those allowing modification of arbitrary kernel objects or creation of new capabilities from untyped memory) are typically not granted to general user-space components. They may be held by a trusted system initialization process, a root task, or a specific trusted management service, which itself operates under strict controls. This upholds the principle of least privilege by ensuring that user-space applications and most system services cannot directly or arbitrarily modify critical kernel state, thereby preventing unauthorized runtime modifications and enhancing overall system stability and security.

## 4. Resource Management for Virtualization (Collaboration with Containerization Expert)

*(Note: This section provides a high-level outline of the microkernel's role and the support it must provide for virtualization. The detailed implementation and specific mechanisms will require further collaboration and input from the Containerization Expert, particularly concerning interfaces and policies for the user-space Virtual Machine Monitor.)*

### 4.1. Microkernel Support for Virtualization
The Skyscope OS microkernel itself will not implement full virtualization features (e.g., it will not contain a hypervisor). Instead, it will provide the fundamental, secure primitives necessary for a user-space Virtual Machine Monitor (VMM), such as a modified QEMU or a custom VMM, to efficiently and securely manage virtual machines. These primitives include:
*   **Secure Memory Management:** Mechanisms to create and manage isolated virtual address spaces for each VM, ensuring that a VM cannot access the memory of the host, other VMs, or the VMM itself, unless explicitly permitted via shared memory capabilities.
*   **CPU Scheduling:** Primitives to allow the VMM to manage the scheduling of VM threads (vCPUs) alongside native host OS threads, ensuring fair allocation of CPU resources and responsiveness.
*   **Secure Inter-Process Communication (IPC):** The standard kernel IPC mechanisms will be used by the VMM to communicate with other system services (e.g., for device emulation, network access, or storage management) and potentially for control communication with the VMs themselves (e.g., via virtualized interrupt controllers or paravirtualized interfaces).

### 4.2. CPU Resource Management
The microkernel's scheduler (e.g., an seL4 MCS-like scheduler or a similar priority-based, time-sliced scheduler) must provide mechanisms that allow a VMM to:
*   Allocate guaranteed CPU time slices or processing budgets to individual VM threads (vCPUs).
*   Define scheduling policies that ensure fair-share distribution of CPU resources among multiple VMs and between VMs and host OS processes.
*   Potentially support real-time scheduling guarantees for specific VM workloads if required, by allowing the VMM to configure appropriate scheduling parameters for vCPU threads using capabilities to scheduling contexts.
The kernel will enforce these allocations, preventing a single VM or misbehaving VMM from monopolizing CPU resources.

### 4.3. Memory Resource Management
The microkernel's memory management system will be crucial for virtualization:
*   It must enforce strict isolation between the physical memory allocated to the host OS, each running VM, and the VMM itself. This is achieved by managing distinct address space objects.
*   It must provide mechanisms for the VMM to request and be granted capabilities to physical memory frames (derived from untyped memory). The VMM can then map these frames into a VM's address space.
*   The kernel must securely manage the assignment and reclamation of physical memory pages to/from VMs. All such operations will be controlled by capabilities held by the VMM, ensuring that the VMM can only manage memory explicitly allocated to it for virtualization purposes.
*   The microkernel will not interpret the contents of VM memory but will ensure that the VMM has the authority to manage mappings for its guest VMs.

### 4.4. I/O Resource Management (Conceptual)
The microkernel's role in I/O resource management for virtualization is primarily one of enabling secure access and control by the VMM, rather than direct involvement in I/O operations.
*   **Emulated Devices:** For emulated devices, I/O operations are typically managed via IPC. The guest VM interacts with a virtual device, trapping into the VMM. The VMM then communicates (via kernel IPC) with user-space device emulator processes or host device drivers to fulfill the I/O request. The kernel's role is to provide secure and efficient IPC.
*   **Hardware Passthrough (e.g., VFIO/IOMMU):** For direct device assignment, the microkernel must provide secure mechanisms for the VMM to:
    *   Gain exclusive, capability-controlled access to physical PCI devices or other hardware resources. This involves the kernel granting the VMM a capability that represents ownership or control of the physical device.
    *   Manage IOMMU (Input/Output Memory Management Unit) mappings. The VMM, using specific capabilities, will instruct the kernel (or a kernel-delegated IOMMU driver) to configure IOMMU page tables. This ensures that Direct Memory Access (DMA) by the passthrough device is restricted to the memory regions assigned to its guest VM, preventing it from accessing host memory or other VMs' memory.
*   The microkernel itself is not directly involved in the data flow of I/O operations once passthrough is established. However, it is responsible for setting up and maintaining the isolation boundaries and ensuring the VMM has the necessary (and strictly limited) capabilities to manage these hardware resources.

### 4.5. Maintaining Host Stability and Security
A core design principle is that virtualization capabilities must not compromise the stability or security of the host Skyscope OS. All resource allocations to VMs (CPU time, memory, device access) are subject to kernel-enforced limits and policies. The capability system is crucial: the VMM will operate with the minimum set of privileges necessary to manage its VMs. It cannot arbitrarily access or modify kernel resources outside of those explicitly granted to it for virtualization tasks. This prevents a compromised or misbehaving VM (or even VMM) from destabilizing the entire system or breaching the security boundaries established by the microkernel.
