<?php
// This file was auto-generated from sdk-root/src/data/acm-pca/2017-08-22/waiters-2.json
return [ 'version' => 2, 'waiters' => [ 'AuditReportCreated' => [ 'description' => 'Wait until a Audit Report is created', 'delay' => 3, 'maxAttempts' => 60, 'operation' => 'DescribeCertificateAuthorityAuditReport', 'acceptors' => [ [ 'matcher' => 'path', 'argument' => 'AuditReportStatus', 'state' => 'success', 'expected' => 'SUCCESS', ], [ 'matcher' => 'path', 'argument' => 'AuditReportStatus', 'state' => 'failure', 'expected' => 'FAILED', ], [ 'matcher' => 'error', 'state' => 'failure', 'expected' => 'AccessDeniedException', ], ], ], 'CertificateAuthorityCSRCreated' => [ 'description' => 'Wait until a Certificate Authority CSR is created', 'delay' => 3, 'maxAttempts' => 60, 'operation' => 'GetCertificateAuthorityCsr', 'acceptors' => [ [ 'matcher' => 'error', 'state' => 'success', 'expected' => false, ], [ 'matcher' => 'error', 'state' => 'retry', 'expected' => 'RequestInProgressException', ], [ 'matcher' => 'error', 'state' => 'failure', 'expected' => 'AccessDeniedException', ], ], ], 'CertificateIssued' => [ 'description' => 'Wait until a certificate is issued', 'delay' => 1, 'maxAttempts' => 60, 'operation' => 'GetCertificate', 'acceptors' => [ [ 'matcher' => 'error', 'state' => 'success', 'expected' => false, ], [ 'matcher' => 'error', 'state' => 'retry', 'expected' => 'RequestInProgressException', ], [ 'matcher' => 'error', 'state' => 'failure', 'expected' => 'AccessDeniedException', ], ], ], ],];
