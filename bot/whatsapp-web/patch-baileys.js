import fs from "node:fs";
import path from "node:path";

const root = path.resolve("node_modules/@whiskeysockets/baileys/lib");
const decodeFile = path.join(root, "Utils/decode-wa-message.js");
const recvFile = path.join(root, "Socket/messages-recv.js");

function patch(file, from, to, label) {
  const source = fs.readFileSync(file, "utf8");
  if (source.includes(to)) {
    console.log(`baileys ${label} already patched`);
    return;
  }
  if (!source.includes(from)) {
    throw new Error(`baileys ${label} patch point is missing`);
  }
  fs.writeFileSync(file, source.replace(from, to));
  console.log(`baileys ${label} patched`);
}

patch(
  decodeFile,
  `if (tag === 'verified_name' && content instanceof Uint8Array) {
                        const cert = proto.VerifiedNameCertificate.decode(content);
                        const details = proto.VerifiedNameCertificate.Details.decode(cert.details);
                        fullMessage.verifiedBizName = details.verifiedName;
                    }`,
  `if (tag === 'verified_name' && content instanceof Uint8Array) {
                        try {
                            const cert = proto.VerifiedNameCertificate.decode(content);
                            const details = proto.VerifiedNameCertificate.Details.decode(cert.details);
                            fullMessage.verifiedBizName = details.verifiedName;
                        }
                        catch (error) {
                            logger.warn({ err: error }, 'ignored a WhatsApp business certificate');
                        }
                    }`,
  "certificate",
);

patch(
  recvFile,
  "logger.error({ error, node }, 'error in handling message');",
  "logger.error({ err: error }, 'error in handling message');",
  "error log",
);
