import { getPublicFiles } from '../../../lib/docs.mjs';

export async function getStaticPaths() {
  return (await getPublicFiles()).map((file) => ({ params: { path: file.source }, props: file }));
}

export function GET({ props }) {
  return new Response(props.content, { headers: { 'Content-Type': 'text/plain; charset=utf-8' } });
}
