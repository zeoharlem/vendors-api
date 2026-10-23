<?php

declare(strict_types=1);

namespace App\Modules\Auth\Presentation;

use App\Modules\Auth\Application\InvalidCredentialsException;
use App\Modules\Auth\Application\RegistrationEmailExistsException;
use App\Modules\Auth\Presentation\Validators\LoginRequestValidator;
use App\Modules\Auth\Presentation\Validators\RegisterRequestValidator;
use Phalcon\Http\Response;
use Phalcon\Mvc\Controller;
use Throwable;

final class AuthController extends Controller
{
    public function indexAction(): Response
    {
        return $this->jsonResponder->success([
            'status' => 'ok',
            'service' => 'At home praising God',
        ]);
    }

    public function loginAction(): Response
    {
        $body = $this->request->getJsonRawBody(true);

        if (!is_array($body)) {
            return $this->jsonResponder->error(
                'INVALID_REQUEST',
                'A valid JSON request body is required.',
                400,
            );
        }

        $fields = [
            'email' => $body['email'] ?? null,
            'password' => $body['password'] ?? null,
        ];

        $typeErrors = [];

        foreach ($fields as $field => $value) {
            if (!is_string($value)) {
                $typeErrors[$field][] = "The {$field} field must be a string.";
            }
        }

        if ($typeErrors !== []) {
            return $this->jsonResponder->error(
                'VALIDATION_ERROR',
                'One or more fields have invalid data types.',
                422,
                $typeErrors,
            );
        }

        $fields['email'] = strtolower(trim($fields['email']));

        $validation = new LoginRequestValidator();

        $messages = $validation->validate($fields);

        $errors = [];

        foreach ($messages as $message) {
            $errors[$message->getField()][] = $message->getMessage();
        }

        if ($errors !== []) {
            return $this->jsonResponder->error(
                'VALIDATION_ERROR',
                'Please provide a valid email and password.',
                422,
                $errors,
            );
        }


        try {
            $result = $this->loginUserService->login($fields);

            return $this->jsonResponder->success($result);
        } catch (InvalidCredentialsException $exception) {
            return $this->jsonResponder->error(
                'INVALID_CREDENTIALS',
                'The email or password is incorrect.',
                401,
            );
        } catch (Throwable $exception) {
            return $this->jsonResponder->error(
                'LOGIN_FAILED',
                'Unable to complete login at this time.',
                500,
            );
        }
    }

    public function registerAction(): Response
    {
        // 1. Read the JSON request body.
        $body = $this->request->getJsonRawBody(true);

        if (!is_array($body)) {
            return $this->jsonResponder->error(
                'INVALID_REQUEST',
                'A valid JSON request body is required.',
                400,
            );
        }

        // 2. Map incoming request fields.
        $fields = [
            'email' => $body['email'] ?? null,
            'password' => trim($body['password']) ?? null,
            'first_name' => $body['first_name'] ?? null,
            'last_name' => $body['last_name'] ?? null,
            'phone' => $body['phone'] ?? null,
        ];

        // 3. Validate PHP data types before normalization.
        $typeErrors = [];

        foreach ($fields as $field => $value) {
            // Phone is optional.
            if ($field === 'phone' && $value === null) {
                continue;
            }

            if (!is_string($value)) {
                $typeErrors[$field][] = "The {$field} field must be a string.";
            }
        }

        if ($typeErrors !== []) {
            return $this->jsonResponder->error(
                'VALIDATION_ERROR',
                'One or more fields have invalid data types.',
                422,
                $typeErrors,
            );
        }

        // 4. Normalize values.
        $fields['email'] = strtolower(trim($fields['email']));
        $fields['first_name'] = trim($fields['first_name']);
        $fields['last_name'] = trim($fields['last_name']);

        $fields['phone'] = isset($fields['phone']) ? trim($fields['phone']) : null;


        if (trim($fields['phone'] ?? '') === '') {
            $fields['phone'] = null;
        }

        // Do not trim the password; spaces can be intentional.

        // 5. Run RegisterRequestValidator.
        $validation = new RegisterRequestValidator();

        $messages = $validation->validate($fields);

        // 6. Collect all validation messages by field.
        $errors = [];

        foreach ($messages as $message) {
            $errors[$message->getField()][] = $message->getMessage();
        }

        // 7. Enforce the password byte limit for bcrypt compatibility.
        if (strlen($fields['password']) > 72) {
            $errors['password'][] = 'Password must not exceed 72 bytes.';
        }

        // 8. Return validation errors, if any.
        if ($errors !== []) {
            return $this->jsonResponder->error(
                'VALIDATION_ERROR',
                'Please correct the registration fields.',
                422,
                $errors,
            );
        }

        // 9. Register the user only when validation succeeds.
        try {
            $user = $this->registerUserService->register($fields);

            return $this->jsonResponder->success($user, 201);
        } catch (RegistrationEmailExistsException $exception) {
            return $this->jsonResponder->error(
                'EMAIL_ALREADY_EXISTS',
                'An account with this email already exists.',
                409,
            );
        } catch (Throwable $exception) {
            // Log the exception through your configured logger.
            // Never log the password or password hash.

            return $this->jsonResponder->error(
                'REGISTRATION_FAILED',
                'Unable to complete registration at this time.:->' . $exception->getMessage(),
                500,
            );
        }
    }
}
