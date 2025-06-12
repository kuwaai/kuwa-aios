# SKYAIFS Detailed SILA Logic V0.1

## 1. Introduction
This document provides a more granular description of the SKYAIFS (Skyscope AI Filesystem) internal operations, AI bot behaviors, and data management processes. It elaborates on the `SKYAIFS SILA Implementation Plan V0.1` from Stage 1, focusing on how these would be expressed using **SILA (Sentient Intermediate Language for Agents)** constructs and conceptual semantic graph logic.

## 2. Detailed SILA Logic for AI Bot Operations

### 2.1. Example: Data Relocation Bot

**SILA State Machine for Relocation Bot:**
`SILA_Define_StateMachine(SKYAIFS_RelocationBot_SM) {
  States { Idle, MonitoringEvents, ThreatDetected, AnalyzingThreat, SelectingTargetData, RequestingRelocationResources, RelocatingData, VerifyingRelocation, ReportingCompletion, HandlingError }

  Transitions {
    Idle -> MonitoringEvents (on: StartEvent)
    MonitoringEvents -> ThreatDetected (on: SILA_ThreatAlert_Event from OS_SecurityService_EP_Cap with high_severity_payload)
    ThreatDetected -> AnalyzingThreat (auto)
    AnalyzingThreat -> SelectingTargetData (on: AnalysisComplete_SILA_Struct_With_Impact_Assessment)
    SelectingTargetData -> RequestingRelocationResources (on: TargetData_SILA_List_Identified)
    RequestingRelocationResources -> RelocatingData (on: Resources_Granted_SILA_CapToken_Bundle) // e.g., capabilities to secure storage zones
    // ... other transitions for success, failure, rollback planning
    RelocatingData -> VerifyingRelocation (on: SILA_Async_BatchCopy_Complete_Event)
    VerifyingRelocation -> ReportingCompletion (on: Verification_Success_SILA_Flag)
    ReportingCompletion -> Idle (auto)
    // Error handling transitions from various states to HandlingError
  }
}`

**Conceptual SILA Graph Snippet (ThreatDetected State -> AnalyzingThreat Action):**
1.  **Receive Event:** SILA event node receives `SILA_ThreatAlert_Event { source_cap: SILA_CapToken, threat_type: SILA_String, confidence_score: SILA_Float, affected_area_guess: SILA_Optional<SILA_Path_String> }`.
2.  **Log Event:** Invoke `SILA_SKYAIFS_LogAudit_Operation(event_data_sila_struct, severity_enum)`.
3.  **Query Metadata Index (SILA Graph Operation):**
    *   Construct `SILA_MetadataQuery_Struct` based on `affected_area_guess` or general policies.
    *   Invoke `SILA_SKYAIFS_QueryMetadataIndex_Operation(query_sila_struct)` which returns a `SILA_Stream<SKYAIFS_File_Descriptor_Cap>`.
4.  **Invoke Analysis AI Model (if applicable, via SILA):**
    *   `SILA_Call_AI_Model_Operation(analysis_model_cap, stream_of_file_descriptor_caps, &analysis_result_sila_struct)`.
5.  **Transition:** Based on `analysis_result_sila_struct`, transition to `SelectingTargetData`.

**Conceptual SILA Graph Snippet (RelocatingData State Action):**
`// Input: TargetData_SILA_List<SILA_CapToken to SKYAIFS_File_Descriptor>
// Input: SecureZone_Storage_Cap: SILA_CapToken
For each file_desc_cap in TargetData_SILA_List:
  // 1. Get current location
  current_blocks_sila_list = SILA_SKYAIFS_GetFileBlockLocations_Operation(file_desc_cap)
  // 2. Allocate new blocks in secure zone (conceptual)
  new_blocks_sila_list_caps = SILA_SKYAIFS_AllocateBlocksInZone_Operation(SecureZone_Storage_Cap, count(current_blocks_sila_list), size_per_block_list)
  // 3. Initiate secure copy (potentially a SILA batch operation)
  SILA_Microkernel_SecureBatchCopy_Operation(current_blocks_sila_list, new_blocks_sila_list_caps, PQC_ReEncrypt_Policy_SILA_Struct)
  // 4. Update metadata (immutable): Create new version of SKYAIFS_File_Descriptor with new block locations and new PQC key ref. This is a complex SILA graph operation.
  SILA_SKYAIFS_UpdateFileMetadata_Operation(file_desc_cap, new_blocks_sila_list_caps, new_pqc_key_ref_cap)
  // 5. (Later) Securely delete old blocks
`

## 3. SILA Semantic Graph Logic for Metadata Management

### 3.1. Example: CreateFile Operation
1.  **Request:** `SILA_SKYAIFS_CreateFile_Request_Event { parent_dir_cap: SILA_CapToken, file_name: SILA_String_Record, owner_info: SILA_Owner_Struct }`.
2.  **Authorization Check (SILA Graph Node):** Verify `parent_dir_cap` grants write/create permissions.
3.  **Allocate `SILA_SKYAIFS_File_Descriptor` Structure (SILA Graph Node):**
    *   Populate fields: `owner_info`, default permissions, timestamps (SILA time service call).
4.  **Generate PQC Content Encryption Key (SILA Crypto Primitive Call):**
    *   `new_content_key_cap = SILA_PQC_GenerateKey_Operation(MLKEM_1024_KeySpec_SILA_Enum)`.
    *   Store a reference/capability to this key within the `SILA_SKYAIFS_File_Descriptor` (e.g., `pqc_content_key_ref_cap: SILA_CapToken`). This reference itself might be to a key vault capability.
5.  **PQC-Sign Descriptor (SILA Crypto Primitive Call):**
    *   `descriptor_signature = SILA_PQC_Sign_Operation<MLDSA_5>(SILA_SKYAIFS_File_Descriptor, skyaifs_metadata_signing_key_cap)`.
    *   Store signature within or alongside the descriptor.
6.  **Update Parent Directory (Immutable SILA Graph Operation):**
    *   Read `SILA_Directory_Listing_Record` associated with `parent_dir_cap`.
    *   Create a *new* version of this listing, adding an entry for `file_name` pointing to the new `SILA_SKYAIFS_File_Descriptor_Cap`.
    *   PQC-Sign the new directory listing.
    *   Update the parent directory capability to point to this new version (this is a complex atomic SILA operation involving CSpace updates if capabilities are versioned).
7.  **Return:** `SILA_SKYAIFS_CreateFile_Response { new_file_descriptor_cap: SILA_CapToken, status: Success_SILA_Enum }`.

## 4. I/O Path Implementation in SILA (Conceptual Read Operation)

1.  **Application Call (SILA):** `app_agent.invoke_sila_call(SKYAIFS_ReadFile_Operation, file_cap: SILA_CapToken, offset: SILA_Int, length: SILA_Int, buffer_cap: SILA_CapToken)`.
2.  **SKYAIFS ReadFile SILA Graph Logic:**
    *   **Capability Validation:** Verify `file_cap` (points to `SILA_SKYAIFS_File_Descriptor`) and `buffer_cap` (points to application's memory buffer).
    *   **Access File Descriptor:** Read the `SILA_SKYAIFS_File_Descriptor` structure.
    *   **Block Mapping:**
        *   Consult the file descriptor's dynamic block map (a SILA graph/structure) to identify logical block(s) corresponding to `offset` and `length`.
        *   This returns a list of `SILA_LogicalBlock_Info_Struct { block_id, block_pqc_key_ref_cap, is_compressed_flag, storage_location_cap, physical_block_id }`.
    *   **For each logical block in sequence:**
        *   **Get Physical Block Capability:** Use `storage_location_cap` and `physical_block_id`.
        *   **Deep Compression Check:** If `is_compressed_flag` is true:
            *   `decompressed_block_data_cap = SILA_DeepCompression_Decompress_Block_Call(physical_block_capability, policy_sila_struct)`.
            *   Data to copy is from `decompressed_block_data_cap`.
        *   Else (not compressed):
            *   `raw_block_data_cap = SILA_Microkernel_ReadBlock_Call(physical_block_capability)`.
            *   Data to copy is from `raw_block_data_cap`.
        *   **PQC Decryption (if content encryption is separate from compression):**
            *   `decrypted_payload_cap = SILA_PQC_Decrypt_Operation<MLKEM_1024>(data_to_copy_from_above, block_pqc_key_ref_cap)`.
        *   **Copy to Application Buffer:** `SILA_Microkernel_CopyToUser_Operation(target_buffer_cap, source_payload_cap, relevant_offset_in_block, length_to_copy_from_block)`.
    *   **Handle I/O Completion (SILA Event/Async):** Signal completion or error to the calling application agent.

## 5. Detailed PQC Operations within SILA Workflows

SILA provides high-level cryptographic primitive operations. AI agents constructing SKYAIFS logic use these:

*   **Encryption:** `SILA_PQC_Encrypt_Operation<MLKEM_1024_Spec_SILA_Enum>(plaintext_data_sila_structure, encryption_key_sila_cap) -> SILA_Ciphertext_Blob_Record`
    *   `MLKEM_1024_Spec_SILA_Enum` ensures adherence to the >=4096-bit security policy.
*   **Decryption:** `SILA_PQC_Decrypt_Operation<MLKEM_1024_Spec_SILA_Enum>(ciphertext_blob_sila_record, decryption_key_sila_cap) -> SILA_Plaintext_Data_Structure`
*   **Signing:** `SILA_PQC_Sign_Operation<MLDSA_5_Spec_SILA_Enum>(data_to_sign_sila_structure, signing_key_sila_cap) -> SILA_PQC_Signature_Record`
*   **Verification:** `SILA_PQC_Verify_Operation<MLDSA_5_Spec_SILA_Enum>(data_to_verify_sila_structure, signature_sila_record, verification_key_sila_cap) -> SILA_Bool_Record`

**Key Usage in SILA:**
*   Keys are always handled as `SILA_CapToken`s (capabilities to key objects, possibly in a secure vault).
*   SILA's type system, when defining PQC-aware structures (e.g., `SILA_PQC_Encrypted<MLKEM_1024, Payload>`), would implicitly require a compatible key capability for operations.
*   The SILA Verifier checks that these cryptographic operations are used correctly (e.g., not using a signing key for encryption).

This detailed logic provides a foundation for AI agents to construct and manage the SKYAIFS using SILA, ensuring security and adaptability.The second document, `SKYAIFS_Detailed_SILA_Logic_V0.1.md`, has been created.

Next, I will create the third document:
**3. AI Pipeline Specialist's Output (Detailed SILA Pipeline & Tooling):**
   *   Create: `SILA_AI_Pipeline_ADK_Tooling_Concepts_V0.1.md`
