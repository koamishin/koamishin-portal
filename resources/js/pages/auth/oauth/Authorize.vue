<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import AuthBase from '@/layouts/AuthLayout.vue';

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
    request: {
        state: string;
    };
}>();

function approve() {
    router.post('/oauth/authorize', {
        state: props.request.state,
        client_id: props.client.id,
        auth_token: props.authToken,
    });
}

function deny() {
    router.delete('/oauth/authorize', {
        data: {
            state: props.request.state,
            client_id: props.client.id,
            auth_token: props.authToken,
        },
    });
}
</script>

<template>
    <AuthBase
        :title="`${client.name} wants access`"
        description="Authorize this application to access your Koamishin account"
    >
        <Head :title="`Authorize ${client.name}`" />

        <div class="space-y-6">
            <div class="rounded-lg border border-border p-4 bg-muted/40 text-sm">
                <p class="font-medium text-foreground">Logged in as:</p>
                <p class="text-muted-foreground">{{ user.name }} ({{ user.email }})</p>
            </div>

            <div v-if="scopes.length > 0" class="space-y-3">
                <p class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                    Requested Permissions
                </p>
                <ul class="space-y-2 text-sm text-foreground">
                    <li v-for="scope in scopes" :key="scope.id" class="flex items-center gap-2">
                        <svg class="h-4 w-4 text-emerald-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                        </svg>
                        <span>{{ scope.description || scope.id }}</span>
                    </li>
                </ul>
            </div>

            <div class="flex items-center gap-3 pt-4">
                <Button class="flex-1" @click="approve">
                    Authorize
                </Button>
                <Button variant="outline" class="flex-1" @click="deny">
                    Cancel
                </Button>
            </div>
        </div>
    </AuthBase>
</template>
