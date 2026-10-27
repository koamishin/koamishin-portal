<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthBase from '@/layouts/AuthLayout.vue';

const form = useForm({
    instance_name: 'Koamishin Auth Portal',
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

function submit() {
    form.post('/setup', {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <AuthBase
        title="Setup Your Auth Portal"
        description="Configure your instance name and create your primary administrator account"
    >
        <Head title="Instance Setup" />

        <form class="space-y-4" @submit.prevent="submit">
            <div class="space-y-2">
                <Label for="instance_name">Company / Instance Name</Label>
                <Input
                    id="instance_name"
                    v-model="form.instance_name"
                    type="text"
                    required
                    placeholder="e.g. Acme Identity Portal"
                />
                <InputError :message="form.errors.instance_name" />
            </div>

            <div class="space-y-2">
                <Label for="name">Super Admin Name</Label>
                <Input
                    id="name"
                    v-model="form.name"
                    type="text"
                    required
                    placeholder="Master Administrator"
                />
                <InputError :message="form.errors.name" />
            </div>

            <div class="space-y-2">
                <Label for="email">Admin Email Address</Label>
                <Input
                    id="email"
                    v-model="form.email"
                    type="email"
                    required
                    placeholder="admin@company.internal"
                />
                <InputError :message="form.errors.email" />
            </div>

            <div class="space-y-2">
                <Label for="password">Master Password</Label>
                <Input
                    id="password"
                    v-model="form.password"
                    type="password"
                    required
                    placeholder="••••••••••••"
                />
                <InputError :message="form.errors.password" />
            </div>

            <div class="space-y-2">
                <Label for="password_confirmation">Confirm Password</Label>
                <Input
                    id="password_confirmation"
                    v-model="form.password_confirmation"
                    type="password"
                    required
                    placeholder="••••••••••••"
                />
                <InputError :message="form.errors.password_confirmation" />
            </div>

            <Button type="submit" class="w-full mt-6" :disabled="form.processing">
                <Spinner v-if="form.processing" class="mr-2" />
                Initialize Portal &amp; Launch
            </Button>
        </form>
    </AuthBase>
</template>
