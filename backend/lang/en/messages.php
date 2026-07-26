<?php

// Common API response messages. Controllers can use __('messages.saved') etc.
// so responses follow the request locale (see SetLocale middleware).
return [
    'saved' => 'Saved successfully.',
    'created' => 'Created successfully.',
    'updated' => 'Updated successfully.',
    'deleted' => 'Deleted successfully.',
    'not_found' => 'Record not found.',
    'unauthorized' => 'You are not authorized to perform this action.',
    'forbidden' => 'This action is forbidden.',
    'error' => 'Something went wrong. Please try again.',
    'login_success' => 'Logged in successfully.',
    'logout_success' => 'Logged out successfully.',
    'invalid_credentials' => 'Invalid email or password.',
];
