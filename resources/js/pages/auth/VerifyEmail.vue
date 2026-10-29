<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { logout } from '@/routes';
import { send } from '@/routes/verification';
import { store as hostedStore } from '@/routes/hosted';

defineProps<{
    status?: string;
    flow?: string | null;
    hosted?: boolean;
}>();
</script>

<template>
    <AuthLayout
        title="Verify email"
        description="Please verify your email address by clicking on the link we just emailed to you."
    >
        <Head title="Email verification" />

        <div
            v-if="status === 'verification-link-sent'"
            class="mb-4 text-center text-sm font-medium text-green-600"
        >
            A new verification link has been sent to the email address you
            provided during registration.
        </div>

        <Form
            v-bind="send.form()"
            :headers="
                hosted && flow
                    ? {
                          'X-Koamishin-Flow': flow,
                          'X-Koamishin-Hosted': '1',
                          'X-Koamishin-Screen': 'verify',
                      }
                    : {}
            "
            class="space-y-6 text-center"
            v-slot="{ processing }"
        >
            <input v-if="flow" type="hidden" name="flow" :value="flow" />
            <input v-if="hosted" type="hidden" name="hosted" value="1" />
            <Button :disabled="processing" variant="secondary">
                <Spinner v-if="processing" />
                Resend verification email
            </Button>

            <Button
                v-if="hosted && flow"
                type="button"
                variant="ghost"
                @click="
                    router.post(hostedStore.url(), {
                        flow,
                        hosted: 1,
                        screen: 'continue',
                    })
                "
            >
                Continue
            </Button>

            <TextLink
                v-if="!flow"
                :href="logout()"
                as="button"
                class="mx-auto block text-sm"
            >
                Log out
            </TextLink>
        </Form>
    </AuthLayout>
</template>
