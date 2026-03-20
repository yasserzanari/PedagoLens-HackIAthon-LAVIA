import * as cdk from 'aws-cdk-lib';
import * as ec2 from 'aws-cdk-lib/aws-ec2';
import * as iam from 'aws-cdk-lib/aws-iam';
import { Construct } from 'constructs';

interface PedagoLensStackProps extends cdk.StackProps {
  keyPairName: string;
}

export class PedagoLensStack extends cdk.Stack {
  constructor(scope: Construct, id: string, props: PedagoLensStackProps) {
    super(scope, id, props);

    // -------------------------------------------------------------------------
    // VPC — utilise le VPC par défaut pour simplifier la démo hackathon
    // -------------------------------------------------------------------------
    const vpc = ec2.Vpc.fromLookup(this, 'DefaultVpc', { isDefault: true });

    // -------------------------------------------------------------------------
    // Security Group
    // -------------------------------------------------------------------------
    const sg = new ec2.SecurityGroup(this, 'PedagoLensSG', {
      vpc,
      description: 'PédagoLens WordPress server',
      allowAllOutbound: true,
    });

    sg.addIngressRule(ec2.Peer.anyIpv4(), ec2.Port.tcp(80),  'HTTP');
    sg.addIngressRule(ec2.Peer.anyIpv4(), ec2.Port.tcp(443), 'HTTPS');
    // SSH — restreindre à ton IP en production
    sg.addIngressRule(ec2.Peer.anyIpv4(), ec2.Port.tcp(22),  'SSH (restreindre en prod)');

    // -------------------------------------------------------------------------
    // IAM Role — accès Bedrock sans credentials dans le code
    // -------------------------------------------------------------------------
    const role = new iam.Role(this, 'PedagoLensEC2Role', {
      assumedBy: new iam.ServicePrincipal('ec2.amazonaws.com'),
      description: 'PédagoLens EC2 — accès Bedrock via IAM Role',
    });

    // Accès Bedrock : invoke uniquement sur Claude
    role.addToPolicy(new iam.PolicyStatement({
      sid: 'BedrockInvokeModel',
      effect: iam.Effect.ALLOW,
      actions: ['bedrock:InvokeModel'],
      resources: [
        `arn:aws:bedrock:*::foundation-model/anthropic.claude-sonnet-4-20250514-v2:0`,
        `arn:aws:bedrock:*::foundation-model/anthropic.claude-3-5-sonnet-20241022-v2:0`,
        `arn:aws:bedrock:*::foundation-model/anthropic.claude-3-haiku-20240307-v1:0`,
      ],
    }));

    // SSM Session Manager (accès SSH sans port 22 ouvert en prod)
    role.addManagedPolicy(
      iam.ManagedPolicy.fromAwsManagedPolicyName('AmazonSSMManagedInstanceCore')
    );

    // -------------------------------------------------------------------------
    // User Data — bootstrap Ubuntu + Apache + PHP 8.1 + MySQL + WordPress
    // -------------------------------------------------------------------------
    const userData = ec2.UserData.forLinux();
    userData.addCommands(
      // Mise à jour système
      'apt-get update -y',
      'apt-get upgrade -y',

      // Apache + PHP 8.1 + extensions WordPress
      'apt-get install -y apache2 php8.1 php8.1-mysql php8.1-curl php8.1-gd php8.1-mbstring php8.1-xml php8.1-zip php8.1-intl libapache2-mod-php8.1',

      // MySQL
      'apt-get install -y mysql-server',
      'systemctl start mysql',
      'systemctl enable mysql',

      // Créer la base WordPress
      "mysql -e \"CREATE DATABASE IF NOT EXISTS pedagolens CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\"",
      "mysql -e \"CREATE USER IF NOT EXISTS 'pedagolens'@'localhost' IDENTIFIED BY 'pedagolens_db_pass_2024';\"",
      "mysql -e \"GRANT ALL PRIVILEGES ON pedagolens.* TO 'pedagolens'@'localhost'; FLUSH PRIVILEGES;\"",

      // WordPress
      'cd /var/www/html',
      'rm -f index.html',
      'wget -q https://wordpress.org/latest.tar.gz',
      'tar -xzf latest.tar.gz --strip-components=1',
      'rm latest.tar.gz',

      // wp-config.php
      'cp wp-config-sample.php wp-config.php',
      "sed -i \"s/database_name_here/pedagolens/\" wp-config.php",
      "sed -i \"s/username_here/pedagolens/\" wp-config.php",
      "sed -i \"s/password_here/pedagolens_db_pass_2024/\" wp-config.php",

      // Git + clone du repo PédagoLens
      'apt-get install -y git',
      // ⚠️  Remplace l'URL par ton repo GitHub
      'git clone https://github.com/TON_USER/pedagolens.git /opt/pedagolens',

      // Symlinks plugins → wp-content/plugins
      'for plugin in pedagolens-core pedagolens-api-bridge pedagolens-teacher-dashboard pedagolens-course-workbench pedagolens-student-twin pedagolens-landing; do',
      '  ln -sf /opt/pedagolens/plugins/$plugin /var/www/html/wp-content/plugins/$plugin',
      'done',

      // Permissions
      'chown -R www-data:www-data /var/www/html',
      'chmod -R 755 /var/www/html',

      // Apache mod_rewrite pour WordPress
      'a2enmod rewrite',
      "sed -i 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf",
      'systemctl restart apache2',
      'systemctl enable apache2',

      // Alias de déploiement rapide
      'echo "alias pl-deploy=\\'cd /opt/pedagolens && git pull && echo Deploy OK\\'" >> /home/ubuntu/.bashrc',
    );

    // -------------------------------------------------------------------------
    // EC2 Instance — t3.small, Ubuntu 22.04 LTS
    // -------------------------------------------------------------------------
    const instance = new ec2.Instance(this, 'PedagoLensServer', {
      vpc,
      instanceType: ec2.InstanceType.of(ec2.InstanceClass.T3, ec2.InstanceSize.SMALL),
      machineImage: ec2.MachineImage.fromSsmParameter(
        '/aws/service/canonical/ubuntu/server/22.04/stable/current/amd64/hvm/ebs-gp2/ami-id'
      ),
      securityGroup: sg,
      role,
      keyName: props.keyPairName,
      userData,
      blockDevices: [{
        deviceName: '/dev/sda1',
        volume: ec2.BlockDeviceVolume.ebs(20), // 20 GB SSD
      }],
    });

    // -------------------------------------------------------------------------
    // Elastic IP — IP fixe pour le DNS
    // -------------------------------------------------------------------------
    const eip = new ec2.CfnEIP(this, 'PedagoLensEIP', {
      instanceId: instance.instanceId,
    });

    // -------------------------------------------------------------------------
    // Outputs
    // -------------------------------------------------------------------------
    new cdk.CfnOutput(this, 'PublicIP', {
      value: eip.ref,
      description: 'IP publique PédagoLens — pointe ton domaine ici',
    });

    new cdk.CfnOutput(this, 'InstanceId', {
      value: instance.instanceId,
      description: 'ID de l\'instance EC2',
    });

    new cdk.CfnOutput(this, 'SSHCommand', {
      value: `ssh -i ~/.ssh/${props.keyPairName}.pem ubuntu@${eip.ref}`,
      description: 'Commande SSH',
    });

    new cdk.CfnOutput(this, 'WordPressURL', {
      value: `http://${eip.ref}`,
      description: 'URL WordPress — complète l\'installation WP via ce lien',
    });
  }
}
