<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterRequest;
use App\Mail\SendRegisterConfirmation;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class AuthController extends Controller
{
    public function register(RegisterRequest $request)
    {
        $user = User::create($request->validated());

        Mail::to($user->email)->send(new SendRegisterConfirmation($user));

        return response()->json([
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => 'customer'],
            'token' => $user->createToken('api')->plainTextToken,
        ], 201);
    }

    public function login(Request $request)
    {
        $data = $request->validated([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', strtoLower($data['email']))->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Invalid credentials.', 'errors' => (object) []], 401);
        }

        return response()->json(['token' => $user->createToken('api')->plainTextToken]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged Out.']);
    }
}
