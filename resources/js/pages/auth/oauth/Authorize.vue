<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import AuthBase from '@/layouts/AuthLayout.vue';
import {
    approve as approveAuthorization,
    deny as denyAuthorization,
} from '@/routes/passport/authorizations';

interface Client {
    id: string;
    name: string;
}

interface User {
    id: number;
    name: string;
    email: string;
}

interface Scope {
    id: string;
    description: string;
}

const props = defineProps<{
    client: Client;
    user: User;
    scopes: Scope[];
    authToken: string;
    csrfToken: string;
    flow?: string | null;
    application?: { name: string; environment: string } | null;
    joiningApplication?: boolean;
    request: {
        state: string;
    };
}>();

/**
 * Submit a real form so the OAuth redirect to the client application is
 * followed by the browser, not by an XHR request.
 */
function submit(method: 'POST' | 'DELETE') {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action =
        method === 'POST'
            ? approveAuthorization.url()
            : denyAuthorization.url();
    form.style.display = 'none';

    const fields: Record<string, string> = {
        _token: props.csrfToken,
        state: props.request.state,
        client_id: props.client.id,
        auth_token: props.authToken,
    };

    if (props.flow) {
        fields.flow = props.flow;
    }

    if (method === 'DELETE') {
        fields._method = 'DELETE';
    }

    for (const [name, value] of Object.entries(fields)) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
    }

    document.body.appendChild(form);
    form.submit();
}

function approve() {
    submit('POST');
}

function deny() {
    submit('DELETE');
}
</script>

<template>
    <AuthBase
        :title="`${application?.name ?? client.name} wants access`"
        description="Authorize this application to access your Koamishin account"
    >
        <Head :title="`Authorize ${application?.name ?? client.name}`" />

        <div class="space-y-6">
            <div
                class="rounded-lg border border-border p-4 bg-muted/40 text-sm"
            >
                <p class="font-medium text-foreground">Logged in as:</p>
                <p class="text-muted-foreground">
                    {{ user.name }} ({{ user.email }})
                </p>
            </div>

            <p v-if="joiningApplication" class="text-sm text-muted-foreground">
                Authorizing will add your Portal account to
                {{ application?.name ?? client.name }} as a member in its
                {{ application?.environment }} environment.
            </p>

            <div v-if="scopes.length > 0" class="space-y-3">
                <p
                    class="text-xs font-semibold uppercase tracking-wider text-muted-foreground"
                >
                    Requested Permissions
                </p>
                <ul class="space-y-2 text-sm text-foreground">
                    <li
                        v-for="scope in scopes"
                        :key="scope.id"
                        class="flex items-center gap-2"
                    >
                        <svg
                            class="h-4 w-4 text-emerald-500 shrink-0"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M5 13l4 4L19 7"
                            />
                        </svg>
                        <span>{{ scope.description || scope.id }}</span>
                    </li>
                </ul>
            </div>

            <div class="flex items-center gap-3 pt-4">
                <Button class="flex-1" @click="approve"> Authorize </Button>
                <Button variant="outline" class="flex-1" @click="deny">
                    Cancel
                </Button>
            </div>
        </div>
    </AuthBase>
</template>
