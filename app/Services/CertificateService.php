<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Medical certificate mutations - extracted verbatim from
 * App\Http\Controllers\CertificateController::index() (Phase 4, Batch 2).
 *
 * The controller keeps HTTP concerns: the POST field reads (including the
 * cert_type key-presence quirk), the ?action=delete / ?print= query reads,
 * the patients/print/list queries, the redirects and view rendering. This
 * service owns the two mutations in the original order:
 *
 *  - issue(): the original's two validation rules in the original order
 *    ("Select a patient." then "Purpose is required."), then on success
 *    generate_cert_no() (no $type - every certificate gets a MED number),
 *    the insert, the audit row and the feature-2 Notify to the patient.
 *    Success carries the new id so the controller can PRG to ?print=.
 *
 *  - delete(): the plain-GET delete + its audit row (any staff member may
 *    delete any certificate - no ownership check, exactly like the
 *    original); the controller keeps the redirect.
 *
 * Error strings, SQL text and call order are byte-identical to the
 * controller's originals; only $request->user()->id became the injected
 * $actor (same value, resolved once by the controller).
 */
class CertificateService
{
    /**
     * Run the issue pipeline: validations -> insert -> audit -> Notify.
     *
     * @param  int  $pid  selected patient user id
     * @param  string  $type  cert_type ('' stays '' - present-but-empty is
     *                        kept, see the controller's field-read comment)
     * @param  string  $purpose  trimmed purpose
     * @param  string  $find  trimmed findings
     * @param  string  $reco  trimmed recommendation
     * @param  int|null  $rest  rest_days (null when blank)
     * @param  int  $actor  authenticated staff id ($request->user()->id)
     * @return array{success: bool, cid: int|null, errors: array<int, string>}
     */
    public function issue(int $pid, string $type, string $purpose, string $find, string $reco, ?int $rest, int $actor): array
    {
        $errors = [];
        if ($pid <= 0) {
            $errors[] = 'Select a patient.';
        }
        if ($purpose === '') {
            $errors[] = 'Purpose is required.';
        }

        if (! $errors) {
            $certNo = generate_cert_no();
            $cid = DB::table('medical_certificates')->insertGetId([
                'cert_no' => $certNo,
                'user_id' => $pid,
                'issued_by' => $actor,
                'cert_type' => $type,
                'purpose' => $purpose,
                'findings' => $find,
                'recommendation' => $reco,
                'rest_days' => $rest,
            ]);

            AuditLogger::log(
                $actor,
                'issue_certificate',
                'medical_certificates',
                $cid,
                "Issued certificate $certNo",
            );

            // fire_event('certificate.issued', ...) had no listener in the
            // reference; feature 2 addresses the certificate's owner.
            Notify::to($pid, 'certificate_issued', [
                'reference_id' => $cid,
                'severity' => 'success',
                'title' => 'Certificate Issued',
                'message' => "Certificate {$certNo} ({$type}) has been issued to you.",
                'action_url' => 'user/my_certificates',
            ]);

            return ['success' => true, 'cid' => $cid, 'errors' => []];
        }

        return ['success' => false, 'cid' => null, 'errors' => $errors];
    }

    /**
     * Delete a certificate + its audit row (no ownership check, original).
     */
    public function delete(int $cid, int $actor): void
    {
        DB::table('medical_certificates')->where('id', $cid)->delete();

        AuditLogger::log(
            $actor,
            'delete_certificate',
            'medical_certificates',
            $cid,
            "Deleted certificate #$cid",
        );
    }
}
