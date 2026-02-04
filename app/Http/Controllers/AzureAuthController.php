<?php

namespace App\Http\Controllers;

use App\Models\Semesters;
use App\Models\Students;
use App\Traits\HttpResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class AzureAuthController extends Controller
{
    use HttpResponse;

    public function redirect(Request $request)
    {
        Log::info('[Azure Login] Redirect entry', [
            'has_error' => $request->has('error'),
            'has_code' => $request->has('code'),
            'redirect' => $request->query('redirect'),
            'state' => $request->query('state'),
            'error' => $request->query('error'),
            'error_description' => $request->query('error_description'),
        ]);

        if ($request->has('error')) {
            return $this->redirectToApp($request, [
                'error' => $request->query('error_description') ?? 'Microsoft login failed',
            ]);
        }

        if ($request->has('code')) {
            return $this->handleCallback($request);
        }

        $redirectUrl = $request->query('redirect');
        if (!$this->isAllowedRedirect($redirectUrl)) {
            Log::warning('[Azure Login] Invalid redirect URL', [
                'redirect' => $redirectUrl,
            ]);
            return $this->errorResponse('Invalid redirect URL', [], null, 422);
        }

        $state = base64_encode(json_encode(['redirect' => $redirectUrl]));

        return Socialite::driver('azure')
            ->stateless()
            ->with(['state' => $state])
            ->redirect();
    }

    private function handleCallback(Request $request)
    {
        Log::info('[Azure Login] Callback entry', [
            'has_code' => $request->has('code'),
            'state' => $request->query('state'),
        ]);

        try {
            $azureUser = Socialite::driver('azure')->stateless()->user();
        } catch (\Throwable $e) {
            Log::warning('[Azure Login] Failed to fetch user', ['error' => $e->getMessage()]);
            return $this->redirectToApp($request, [
                'error' => 'Unable to authenticate with Microsoft',
            ]);
        }

        $email =
            $azureUser->getEmail()
            ?? ($azureUser->user['mail'] ?? null)
            ?? ($azureUser->user['userPrincipalName'] ?? null);

        if (!$email) {
            Log::warning('[Azure Login] Email not found in Azure profile', [
                'azure_user' => $azureUser->user ?? null,
            ]);
            return $this->redirectToApp($request, [
                'error' => 'Microsoft account email not found',
            ]);
        }

        $student = Students::where('SchoolEmail', $email)->where('CurrentStudent', 'Y')->first();
        if (!$student) {
            Log::warning('[Azure Login] No matching student account', [
                'email' => $email,
            ]);
            return $this->redirectToApp($request, [
                'error' => 'No matching student account found',
            ]);
        }

        $student->tokens()->delete();
        $token = $student->createToken('mobile', ['student:*'], now()->addDay());

        $currentSemester = Semesters::getCurrentSemester(
            ['SemesterID', 'SemesterName', 'FExam1', 'FExam2', 'MidCutOffDate', 'StartDate', 'EndDate']
        );

        if ($currentSemester) {
            $today = date('Y-m-d');
            if ($today < $currentSemester->StartDate) {
                $progressText = 'term_not_started';
            } elseif ($today >= $currentSemester->StartDate && $today < $currentSemester->MidCutOffDate) {
                $progressText = 'first_half';
            } elseif ($today >= $currentSemester->MidCutOffDate && $today <= $currentSemester->EndDate) {
                $progressText = 'second_half';
            } else {
                $progressText = 'end_of_term';
            }
            $currentSemester->progressText = $progressText;
        }

        Log::info('[Azure Login] Success', [
            'email' => $email,
            'student_id' => $student->StudentID ?? null,
        ]);

        return $this->redirectToApp($request, [
            'token' => $token->plainTextToken,
            'email' => $email,
        ]);
    }

    private function redirectToApp(Request $request, array $params)
    {
        $redirectUrl = $this->resolveRedirect($request);
        if (!$redirectUrl || !$this->isAllowedRedirect($redirectUrl)) {
            Log::warning('[Azure Login] Missing/invalid redirect in callback', [
                'redirect' => $redirectUrl,
                'state' => $request->query('state'),
            ]);
            return $this->errorResponse('Missing redirect URL', [], null, 422);
        }

        $separator = Str::contains($redirectUrl, '?') ? '&' : '?';
        Log::info('[Azure Login] Redirecting to app', [
            'redirect' => $redirectUrl,
            'params' => array_keys($params),
        ]);
        return redirect()->away($redirectUrl . $separator . http_build_query($params));
    }

    private function resolveRedirect(Request $request): ?string
    {
        $state = $request->query('state');
        if ($state) {
            $decoded = json_decode(base64_decode((string) $state), true);
            if (is_array($decoded) && !empty($decoded['redirect'])) {
                return $decoded['redirect'];
            }
        }

        $redirectUrl = $request->query('redirect');
        return $redirectUrl ?: null;
    }

    private function isAllowedRedirect(?string $url): bool
    {
        if (!$url) return false;
        return (bool) preg_match('/^(myapp|exp|exp\\+[a-z0-9]+):\\/\\//i', $url);
    }
}
