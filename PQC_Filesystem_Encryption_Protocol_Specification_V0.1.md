# PQC Filesystem Encryption Protocol Specification V0.1

## 1. Introduction
This document outlines the initial specification for Post-Quantum Cryptography (PQC) protocols to be used in Skyscope OS's unmodifiable, AI-driven filesystem. The focus is on ensuring long-term data security and integrity.

## 2. Chosen PQC Algorithms (as per Table 2)

### 2.1. Key Encapsulation/Encryption
*   **Primary:** ML-KEM (CRYSTALS-Kyber) - FIPS 203
    *   *Role:* Encrypting file content and unique per-file/block encryption keys.
*   **Backup/Alternative:** HQC (Haraka, KINDI, Cattitude) - NIST selection (draft expected 2026)
    *   *Role:* Alternative for key encapsulation, evaluated upon finalization.

### 2.2. Digital Signatures
*   **Primary:** ML-DSA (CRYSTALS-Dilithium) - FIPS 204, or FALCON - FIPS 206 (draft)
    *   *Role:* Signing critical filesystem metadata (inodes, directories, allocation bitmaps) and ensuring integrity of append-only data structures and versioned file content. FALCON may be preferred for signature size if performance is adequate.
*   **Alternative:** SLH-DSA (SPHINCS+) - FIPS 205
    *   *Role:* Backup signature scheme, particularly if concerns arise with primary choices or for specific high-assurance needs.

### 2.3. Secure Hash Functions
*   **Primary:** SHA3-256 / SHA3-512 - FIPS 202
    *   *Role:* Constructing Merkle trees for filesystem integrity, general hashing needs.
*   **Exploration:** Dedicated PQC hash proposals will be monitored if standardized and vetted by NIST, but SHA3 remains the baseline.

## 3. Application to Filesystem Components

*   **File Content Encryption:** ML-KEM will be used to encapsulate symmetric keys (e.g., AES-256 in GCM mode) used for encrypting file content. Each file, or potentially each block, will use a unique symmetric key derived via a PQC KEM.
*   **Metadata Signing:** ML-DSA (or FALCON) will be used to sign all critical filesystem metadata structures, including inode tables, directory file entries, allocation bitmaps, and pointers within append-only log structures.
*   **Append-Only Logs & Versioning:** The integrity of append-only logs and versioned file content will be ensured by PQC digital signatures on each entry or version block. This prevents unauthorized modification or tampering.
*   **Merkle Tree Integrity:** SHA3-256/512 will be used to construct Merkle trees over filesystem data blocks and metadata. The root hash of the Merkle tree will itself be signed using ML-DSA (or FALCON) and stored securely, providing a verifiable chain of integrity for the entire filesystem.

## 4. Initial Key Management Considerations

*   **Key Derivation:** Per-file/block symmetric encryption keys will likely be derived from a master secret or a set of master keys specific to user or filesystem partitions. This derivation process will employ PQC KEMs to protect the derived keys.
*   **Secure Vaulting:** Master PQC keys (both private keys for signing and KEMs) must be stored in a highly secure vault. The specifics of this vault will be detailed by the Security Analyst, but it must protect against both software and physical attacks.
*   **Key Hierarchy:** A hierarchical key structure is anticipated, where master keys protect intermediate keys, which in turn protect data encryption/signing keys. This limits the exposure of master keys.
*   **Revocation:** Mechanisms for key revocation and rotation will need to be defined, though this is a more complex topic for later specification versions.

This V0.1 specification provides a foundational direction. Further details on key lifecycles, specific parameter choices, and performance implications will be addressed in subsequent revisions.
