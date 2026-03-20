#!/usr/bin/env node
import 'source-map-support/register';
import * as cdk from 'aws-cdk-lib';
import { PedagoLensStack } from '../lib/pedagolens-stack';

const app = new cdk.App();

new PedagoLensStack(app, 'PedagoLensStack', {
  env: {
    account: process.env.CDK_DEFAULT_ACCOUNT,
    region:  process.env.CDK_DEFAULT_REGION ?? 'us-east-1',
  },
  // Passe ton keypair EC2 existant (créé dans la console AWS)
  keyPairName: app.node.tryGetContext('keyPairName') ?? 'pedagolens-key',
});
