<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\User;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
            'timezones' => DateTimeZone::listIdentifiers(),
        ]);
    }

    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $details = $request->safe()->only(['name', 'email', 'timezone']);
        $old = $user->photo;

        if ($request->hasFile('photo')) {
            $details['photo'] = $request->file('photo')->store(User::PHOTO_DIRECTORY, 'local');
        } elseif ($request->boolean('remove_photo')) {
            $details['photo'] = null;
        }

        $user->update($details);

        if ($old !== null && $old !== $user->photo) {
            Storage::disk('local')->delete($old);
        }

        return redirect()->route('profile.edit')->with('success', 'Profile saved.');
    }

    /**
     * The signed-in user's own photo. It is on the private disk, so it is sent from here.
     */
    public function photo(Request $request): StreamedResponse
    {
        $photo = $request->user()->photo;

        abort_if($photo === null || ! Storage::disk('local')->exists($photo), 404);

        return Storage::disk('local')->response($photo, headers: ['Cache-Control' => 'private, max-age=86400']);
    }

    /**
     * A new remember token signs out the remember-me cookies on other devices.
     */
    public function updatePassword(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('updatePassword', [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $request->user()->forceFill([
            'password' => $validated['password'],
            'remember_token' => Str::random(60),
        ])->save();

        return redirect()->route('profile.edit')->with('success', 'Password changed.');
    }
}
