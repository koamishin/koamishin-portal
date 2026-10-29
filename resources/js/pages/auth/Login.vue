<script setup lang="ts">
import { Form, Head, router, usePage } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import SignInWithPasskeyButton from '@/components/SignInWithPasskeyButton.vue';
import SocialLoginButtons from '@/components/SocialLoginButtons.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthBase from '@/layouts/AuthLayout.vue';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';
import { store as hostedStore } from '@/routes/hosted';
import type { AppPageProps } from '@/types';

const props = defineProps<{
    application?: {
        name: string;
        organization: string;
        environment: string;
        allowSignups: boolean;
        passkeysEnabled: boolean;
        loginTitle?: string | null;
        loginDescription?: string | null;
    } | null;
    flow?: string | null;
    hosted?: boolean;
    status?: string;
    canResetPassword: boolean;
    canRegister: boolean;
}>();

const page =
    usePage<AppPageProps<{ settingsFeatures?: { passkeys?: boolean } }>>();
const passkeysEnabled = props.application
    ? props.application.passkeysEnabled
    : (page.props.settingsFeatures?.passkeys ?? true);
</script>

<template>
    <AuthBase
        :title="
            application?.loginTitle ||
            (application
                ? `Sign in to ${application.name}`
                : 'Log in to your account')
        "
        :description="
            application?.loginDescription ||
            (application
                ? `${application.organization} · ${application.environment}`
                : 'Enter your email and password below to log in')
        "
    >
        <Head
            :title="application ? `Sign in to ${application.name}` : 'Log in'"
        />

        <div
            v-if="status"
            class="mb-4 text-center text-sm font-medium text-green-600"
        >
            {{ status }}
        </div>

        <div
            v-if="$page.props.errors?.social"
            class="mb-4 text-center text-sm font-medium text-destructive"
        >
            {{ $page.props.errors.social }}
        </div>

        <Form
            v-bind="store.form()"
            :headers="
                hosted && flow
                    ? {
                          'X-Koamishin-Flow': flow,
                          'X-Koamishin-Hosted': '1',
                          'X-Koamishin-Screen': 'login',
                      }
                    : {}
            "
            :reset-on-success="['password']"
            v-slot="{ errors, processing }"
            class="flex flex-col gap-6"
        >
            <input v-if="flow" type="hidden" name="flow" :value="flow" />
            <input v-if="hosted" type="hidden" name="hosted" value="1" />
            <div class="grid gap-6">
                <div class="grid gap-2">
                    <Label for="email">Email address</Label>
                    <Input
                        id="email"
                        type="email"
                        name="email"
                        required
                        autofocus
                        :tabindex="1"
                        autocomplete="email"
                        placeholder="email@example.com"
                    />
                    <InputError :message="errors.email" />
                </div>

                <div class="grid gap-2">
                    <div class="flex items-center justify-between">
                        <Label for="password">Password</Label>
                        <TextLink
                            v-if="canResetPassword"
                            :href="request()"
                            class="text-sm"
                            :tabindex="5"
                        >
                            Forgot password?
                        </TextLink>
                    </div>
                    <Input
                        id="password"
                        type="password"
                        name="password"
                        required
                        :tabindex="2"
                        autocomplete="current-password"
                        placeholder="Password"
                    />
                    <InputError :message="errors.password" />
                </div>

                <div class="flex items-center justify-between">
                    <Label
                        v-if="!flow"
                        for="remember"
                        class="flex items-center space-x-3"
                    >
                        <Checkbox id="remember" name="remember" :tabindex="3" />
                        <span>Remember me</span>
                    </Label>
                </div>

                <Button
                    type="submit"
                    class="mt-4 w-full"
                    :tabindex="4"
                    :disabled="processing"
                    data-test="login-button"
                >
                    <Spinner v-if="processing" />
                    Log in
                </Button>
            </div>

            <div
                class="text-center text-sm text-muted-foreground"
                v-if="canRegister && (!application || application.allowSignups)"
            >
                Don't have an account?
                <button
                    v-if="hosted && flow"
                    type="button"
                    class="underline underline-offset-4"
                    @click="
                        router.post(hostedStore.url(), {
                            flow,
                            screen: 'register',
                            hosted: 1,
                        })
                    "
                >
                    Sign up
                </button>
                <TextLink
                    v-else
                    :href="
                        props.flow
                            ? register({ query: { flow: props.flow } })
                            : register()
                    "
                    :tabindex="5"
                    >Sign up</TextLink
                >
            </div>
        </Form>

        <SignInWithPasskeyButton
            v-if="passkeysEnabled"
            :flow="flow"
            :hosted="hosted"
        />

        <SocialLoginButtons :flow="flow" :hosted="hosted" />
    </AuthBase>
</template>
