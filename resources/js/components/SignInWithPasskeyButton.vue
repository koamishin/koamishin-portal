<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Passkeys, UserCancelledError } from '@laravel/passkeys';
import { Fingerprint } from '@lucide/vue';
import { onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { loginOptions, login } from '@/routes/passkey';
import { store as hostedStore } from '@/routes/hosted';

const props = defineProps<{ flow?: string | null; hosted?: boolean }>();

const supported = ref(true);
const loading = ref(false);
const error = ref<string | null>(null);

onMounted(() => {
    supported.value = Passkeys.isSupported();
});

const signIn = async () => {
    if (!supported.value || loading.value) {
        return;
    }

    loading.value = true;
    error.value = null;

    try {
        Passkeys.configure({
            fetch: {
                headers: {
                    'X-Koamishin-Flow': props.hosted ? (props.flow ?? '') : '',
                    'X-Koamishin-Hosted': props.hosted ? '1' : '',
                },
            },
        });
        const result = await Passkeys.verify(
            props.flow && !props.hosted
                ? {
                      routes: {
                          options: loginOptions.url({
                              query: { flow: props.flow },
                          }),
                          submit: login.url({ query: { flow: props.flow } }),
                      },
                  }
                : {},
        );
        if (
            props.hosted &&
            props.flow &&
            (result as { hosted?: boolean } | null)?.hosted
        ) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = hostedStore.url();
            const csrf =
                document.querySelector<HTMLMetaElement>(
                    'meta[name="csrf-token"]',
                )?.content ?? '';
            for (const [name, value] of Object.entries({
                _token: csrf,
                flow: props.flow,
                hosted: '1',
                screen: 'continue',
            })) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = value;
                form.appendChild(input);
            }
            document.body.appendChild(form);
            form.submit();
            return;
        }
        const redirect = (result as { redirect?: string } | null)?.redirect;

        if (redirect) {
            router.visit(redirect);

            return;
        }

        router.reload();
    } catch (err: any) {
        if (err instanceof UserCancelledError) {
            return;
        }

        error.value = err?.message ?? 'Passkey sign in failed.';
    } finally {
        loading.value = false;
    }
};
</script>

<template>
    <div class="space-y-3">
        <div class="relative">
            <div class="absolute inset-0 flex items-center">
                <span
                    class="w-full border-t border-border"
                    aria-hidden="true"
                />
            </div>
            <div class="relative flex justify-center text-xs uppercase">
                <span class="bg-background px-2 text-muted-foreground">
                    Or continue with
                </span>
            </div>
        </div>

        <Button
            type="button"
            variant="outline"
            class="w-full"
            :disabled="!supported || loading"
            data-test="sign-in-with-passkey-button"
            @click="signIn"
        >
            <Spinner v-if="loading" class="mr-2" />
            <Fingerprint v-else class="mr-2 h-4 w-4" />
            {{
                loading
                    ? 'Signing in…'
                    : supported
                      ? 'Sign in with passkey'
                      : 'Passkeys not supported'
            }}
        </Button>

        <p
            v-if="error"
            role="alert"
            class="text-center text-sm text-destructive"
        >
            {{ error }}
        </p>
    </div>
</template>
