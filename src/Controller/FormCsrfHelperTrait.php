<?php

namespace App\Controller;

use Symfony\Component\Form\FormInterface;

trait FormCsrfHelperTrait
{
    private function formHasCsrfError(FormInterface $form): bool
    {
        if ($form->has('_token') && $form->get('_token')->getErrors()->count() > 0) {
            return true;
        }

        foreach ($form->getErrors(true) as $error) {
            $message = mb_strtolower($error->getMessage());
            if (str_contains($message, 'csrf') || str_contains($message, 'jeton')) {
                return true;
            }
        }

        return false;
    }
}
